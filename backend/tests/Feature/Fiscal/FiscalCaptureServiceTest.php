<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalFailure;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Contracts\FailedEntry;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\ArrayLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O serviço de captura: quem decide se o cliente é consultável, quem guarda o lote
 * inteiro antes de mexer na posição, e quem para a posição de avançar.
 *
 * Nenhum teste aqui toca a rede: o `FiscalConnector` é um falso ligado no
 * container e `preventStrayRequests` explode se alguma requisição escapar. Nenhum
 * mock de writer também — a recusa de gravação é a real, a chave de acesso que
 * não fecha, e é por ela que a captura é exercitada sem simular a gravação.
 */
class FiscalCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaves de acesso com o dígito verificador que o módulo 11 da NT exige. A
     * identidade do documento é a chave e não a posição, então dois documentos do
     * mesmo lote precisam de chaves diferentes — e a última não fecha, que é a
     * recusa do writer.
     */
    private const CHAVE_100 = '33333333333333333333333333333333333333331007';

    private const CHAVE_101 = '33333333333333333333333333333333333333331015';

    private const CHAVE_150 = '33333333333333333333333333333333333333331503';

    private const CHAVE_200 = '33333333333333333333333333333333333333332003';

    private const CHAVE_QUE_NAO_FECHA = '33333333333333333333333333333333333333332004';

    /**
     * As chamadas que chegaram ao conector, na ordem. Vazio é a prova de que não
     * houve chamada de saída, que é o que a NT exige de um cliente sem credencial,
     * dentro da janela de bloqueio ou com histórico interrompido.
     *
     * @var list<array{client: Client, fromNsu: int, limit: int}>
     */
    private array $pulls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
        Storage::fake('certificates');
        Http::preventStrayRequests();
    }

    public function test_captures_documents_and_advances_the_cursor(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // A posição devolvida vai além do último documento do lote, e é ela que
        // entra no cursor: `ultNSU` é o valor que a resposta trouxe, nunca o
        // valor local somado de um. Nem 102, nem 201.
        //
        // As duas posições do lote são vizinhas de propósito: posição faltando
        // no meio é buraco, e buraco não deixa a posição andar — o que tem
        // teste próprio em `FiscalReconciliationTest`, junto com a lacuna que
        // ele grava.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(101, self::CHAVE_101)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertTrue($outcome->ran);
        $this->assertNull($outcome->skipReason);
        $this->assertSame(2, $outcome->stored);
        $this->assertSame(0, $outcome->fromNsu);
        $this->assertSame(200, $outcome->toNsu);
        $this->assertSame(2, FiscalDocument::count());
        $this->assertSame([100, 101], FiscalDocument::query()->orderBy('nsu')->pluck('nsu')->all());

        $cursor = $this->cursor($client);
        $this->assertSame(200, $cursor->last_nsu);
        $this->assertNull($cursor->last_error);
        $this->assertNotNull($cursor->last_success_at);
    }

    public function test_repeated_capture_is_idempotent(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 100,
            mayAdoptPosition: true,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);
        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // A segunda execução volta a pedir a posição que a primeira gravou — o
        // cursor, não o documento — e o mesmo lote reentra por cima. Uma linha
        // só, que é a identidade por chave de acesso funcionando.
        $this->assertSame([0, 100], array_column($this->pulls, 'fromNsu'));
        $this->assertSame(1, FiscalDocument::count());
        $this->assertSame(100, $this->cursor($client)->last_nsu);
    }

    public function test_skips_a_client_without_certificate_and_does_not_call_out(): void
    {
        [$client] = $this->tenant(withCertificate: false);

        $this->bindConnector(fn (): PullResult => $this->batch([], 0, true));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertSame([], $this->pulls);
        $this->assertFalse($outcome->ran);
        $this->assertSame(FiscalSkipReason::NoCertificate, $outcome->skipReason);
        $this->assertSame(0, $outcome->stored);
        $this->assertSame(0, $outcome->fromNsu);
        $this->assertSame(0, $outcome->toNsu);

        // Cliente não capturável não é cliente que não roda: nada foi tentado, e
        // a coluna que diria "rodamos" continua vazia.
        $this->assertNull($this->cursor($client)->last_run_at);
    }

    public function test_skips_a_client_whose_certificate_has_no_stored_password(): void
    {
        [$client] = $this->tenant(withCertificate: false);

        // O certificado existe e está no prazo — o que falta é a senha, que é o
        // que a factory `withoutPassword()` remove. Sem ela não há PKCS#12 para
        // abrir, e o painel precisa dizer "reenvie o certificado", não "capture
        // falhou".
        ClientCertificate::factory()->withoutPassword()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
        ]);

        $this->bindConnector(fn (): PullResult => $this->batch([], 0, true));

        $outcome = $this->service()->capture($client->refresh(), FiscalSource::NfeDistribuicao);

        $this->assertSame([], $this->pulls);
        $this->assertSame(FiscalSkipReason::NoCertificate, $outcome->skipReason);
    }

    public function test_skips_a_client_whose_certificate_expired(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $certificate = $client->currentCertificate;
        $certificate->forceFill(['valid_until' => now()->subDay()])->save();

        $this->bindConnector(fn (): PullResult => $this->batch([], 0, true));

        $outcome = $this->service()->capture($client->refresh(), FiscalSource::NfeDistribuicao);

        $this->assertSame([], $this->pulls);
        $this->assertSame(FiscalSkipReason::NoCertificate, $outcome->skipReason);
    }

    public function test_certificado_com_senha_indecifravel_exige_reenvio(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // A coluna tem senha e a senha não abre: `APP_KEY` rotacionada, valor
        // truncado ou lixo antigo. O certificado existe e está no prazo, então
        // este não é um cliente sem certificado — é um cliente cujo A1 tem de
        // voltar, e essa é a diferença que a carteira precisa enxergar.
        $client->currentCertificate->forceFill(['password_encrypted' => 'nao-e-um-ciphertext'])->save();

        $this->cursor($client)->forceFill(['last_nsu' => 900])->save();

        $this->bindConnector(fn (): PullResult => $this->batch([], 1000, true));

        $outcome = $this->service()->capture($client->refresh(), FiscalSource::NfeDistribuicao);

        $this->assertSame([], $this->pulls);
        $this->assertFalse($outcome->ran);
        $this->assertSame(FiscalSkipReason::NoCertificate, $outcome->skipReason);

        // `last_error` é a classificação estável que a API vai ler, e não uma
        // frase montada a partir da exceção: o motivo é o reenvio, e nada mais.
        $cursor = $this->cursor($client);
        $this->assertSame('certificate_reupload', $cursor->last_error);

        // A posição não se move e a coluna que diria "rodamos" continua vazia:
        // sem senha não há o que consultar, e uma posição que anda por cima de
        // uma consulta que não aconteceu perde documento em silêncio.
        $this->assertSame(900, $cursor->last_nsu);
        $this->assertNull($cursor->last_run_at);
    }

    public function test_certificado_reenviado_limpa_o_motivo_apos_a_captura(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $client->currentCertificate->forceFill(['password_encrypted' => 'nao-e-um-ciphertext'])->save();

        $this->bindConnector(fn (): PullResult => $this->batch([], 0, true));

        $this->service()->capture($client->refresh(), FiscalSource::NfeDistribuicao);

        $this->assertSame('certificate_reupload', $this->cursor($client)->last_error);

        $this->reuploadCertificate($client);

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $this->service()->capture($client->refresh(), FiscalSource::NfeDistribuicao);

        // A marcação é o estado de agora, não um histórico de estados: um cliente
        // que voltou a ser capturável não pode continuar na lista de quem precisa
        // reenviar o certificado, senão o aviso vira ruído e ninguém lê mais.
        $this->assertSame(200, $this->cursor($client)->last_nsu);
        $this->assertNull($this->cursor($client)->last_error);
    }

    public function test_skips_a_client_inside_a_block_window(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill([
            'last_nsu' => 900,
            'blocked_until' => now()->addMinutes(30),
        ])->save();

        $this->bindConnector(fn (): PullResult => $this->batch([], 1000, true));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // Retomar antes de completar a hora zera a contagem do fisco e a
        // reinicia, então aqui não há chamada nenhuma: a parada é absoluta.
        $this->assertSame([], $this->pulls);
        $this->assertFalse($outcome->ran);
        $this->assertSame(FiscalSkipReason::Blocked, $outcome->skipReason);
        $this->assertNotSame(FiscalSkipReason::Locked, $outcome->skipReason);

        $cursor = $this->cursor($client);
        $this->assertSame(900, $cursor->last_nsu);
        $this->assertTrue($cursor->blocked_until->isFuture());
    }

    public function test_skips_a_client_whose_history_is_interrupted(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill([
            'last_nsu' => 900,
            'last_seen_at' => now()->subDays((int) config('fiscal.continuity_days') + 1),
        ])->save();

        $this->bindConnector(fn (): PullResult => $this->batch([], 1000, true));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // O fisco não gera posições retroativas para o período que deixou de
        // fora, então continuar consultando não recuperaria nada: o histórico é
        // interrompido e a captura para, em silêncio não.
        $this->assertSame([], $this->pulls);
        $this->assertFalse($outcome->ran);
        $this->assertSame(FiscalSkipReason::Interrupted, $outcome->skipReason);
        $this->assertSame(900, $outcome->fromNsu);
        $this->assertSame(900, $this->cursor($client)->last_nsu);
    }

    public function test_captures_a_client_seen_inside_the_continuity_window(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill([
            'last_seen_at' => now()->subDays((int) config('fiscal.continuity_days') - 1),
        ])->save();

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertCount(1, $this->pulls);
        $this->assertTrue($outcome->ran);
        $this->assertSame(200, $this->cursor($client)->last_nsu);
    }

    public function test_does_not_advance_the_cursor_when_storing_fails(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill(['last_nsu' => 50])->save();

        // A chave que não fecha é a recusa real do writer: ele se recusa a
        // transformar dado do serviço em caminho e levanta `RuntimeException`
        // antes de gravar qualquer coisa. É a falha de gravação sem simular a
        // gravação.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_QUE_NAO_FECHA)],
            lastNsu: 100,
            mayAdoptPosition: true,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursor($client);

        // A posição não avança por cima de um documento que não entrou, e o
        // documento que não entrou continua sendo o que a consulta seguinte vai
        // pedir de novo.
        $this->assertSame(50, $cursor->last_nsu);
        $this->assertSame(50, $outcome->toNsu);
        $this->assertSame(0, $outcome->stored);
        $this->assertSame(0, FiscalDocument::count());
        $this->assertNotNull($cursor->last_error);
    }

    public function test_keeps_storing_the_batch_past_a_document_it_cannot_store(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(150, self::CHAVE_QUE_NAO_FECHA), $this->pulled(200, self::CHAVE_200)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // Uma posição ilegível é um buraco a reconciliar, não o fim da fila: os
        // documentos dos dois lados dela continuam gravados, e o que não avançou
        // foi a posição.
        $this->assertTrue($outcome->ran);
        $this->assertSame(2, $outcome->stored);
        $this->assertSame([100, 200], FiscalDocument::query()->orderBy('nsu')->pluck('nsu')->all());
        $this->assertSame(0, $this->cursor($client)->last_nsu);
        $this->assertSame(0, $outcome->toNsu);
    }

    public function test_does_not_adopt_the_position_the_batch_authorized_because_one_document_is_missing(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // A autorização do serviço e a integridade do lote são duas coisas: o
        // serviço autoriza a posição que devolveu, e o lote inteiro ter entrado
        // é o que decide se a posição pode ser gravada. Aqui a primeira é sim e a
        // segunda é não, e a segunda manda.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_QUE_NAO_FECHA)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertSame(0, $outcome->stored);
        $this->assertSame(0, $outcome->toNsu);
        $this->assertSame(0, $this->cursor($client)->last_nsu);
    }

    public function test_does_not_advance_the_cursor_when_the_batch_has_an_unreadable_entry(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill(['last_nsu' => 50])->save();

        // O conector recusa uma entrada do lote e diz que a posição não pode ser
        // adotada. A posição devolvida (200) é a posição depois do buraco, e
        // gravá-la pediria ao fisco o que vem depois do buraco para sempre — o
        // documento ilegível nunca mais seria pedido.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 200,
            mayAdoptPosition: false,
            failures: [new FailedEntry(150, 'resNFe_v1.01.xsd', 'DocZipDecoder não decodificou o payload comprimido.')],
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertSame(1, $outcome->stored);
        $this->assertSame(1, FiscalDocument::count());
        $this->assertSame(50, $outcome->toNsu);
        $this->assertSame(50, $this->cursor($client)->last_nsu);
    }

    public function test_records_an_unreadable_entry_with_its_position_and_no_payload(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 200,
            mayAdoptPosition: false,
            failures: [new FailedEntry(150, 'resNFe_v1.01.xsd', 'DocZipDecoder não decodificou o payload comprimido.')],
        ));

        Log::spy();

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // A posição é o que reconcilia depois, e é a única coisa que a frase do
        // conector não traz. O `docZip` e a chave não entram: o que se registra é
        // o fato, e quem for atrás da posição a reconstrói pela chave da linha.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.capture.entrada_ilegivel'
                && $context['client_id'] === $client->getKey()
                && $context['nsu'] === 150
                && $context['reason'] === 'DocZipDecoder não decodificou o payload comprimido.'
                && ! str_contains(serialize($context), '<docZip>'));
    }

    public function test_leaves_the_stored_position_untouched_when_no_document_is_located(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill(['last_nsu' => 900])->save();

        // "Nenhum documento localizado": o serviço não entregou nada, e o que
        // devolve nesse caso é o eco da posição pedida — ou zero, quando o campo
        // não vem. Gravar isso sobrescreveria o cursor com o valor anterior e
        // apagaria a posição que a consulta anterior tinha conquistado.
        //
        // O `137` é o que classifica a resposta, então vem junto: um `null`
        // aqui seria uma forma que o conector não produz, e a forma que ele
        // produz — resposta com `lastNsu` diferente da posição guardada — é a
        // única em que `assertSame(900, …)` consegue falhar.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [],
            lastNsu: 0,
            mayAdoptPosition: false,
            blockedUntil: CarbonImmutable::now()->addHour(),
            failure: FiscalFailure::NoDocuments,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursor($client);

        $this->assertTrue($outcome->ran);
        $this->assertSame(0, $outcome->stored);
        $this->assertSame(900, $outcome->fromNsu);
        $this->assertSame(900, $outcome->toNsu);
        $this->assertSame(900, $cursor->last_nsu);
        $this->assertNotNull($cursor->blocked_until);
        $this->assertNull($cursor->last_error);
    }

    public function test_rejeicao_por_consumo_indevido_marca_a_coluna_com_o_rotulo_fixo(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // Consumo indevido: a pausa de uma hora e a de "nenhum documento
        // localizado" são indistinguíveis pelo relógio, e só uma delas é um
        // item de atenção. O rótulo é o que sobrevive para o painel, e ele é
        // fixo: nada do que o fisco escreveu no `xMotivo` entra na coluna.
        //
        // A posição vem dentro do corpo da própria rejeição, e é a única
        // alavanca de recuperação que o fisco oferece: perdê-la custaria
        // recomeçar do começo, então este é o caso em que a posição é gravada
        // mesmo sem nenhum documento.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [],
            lastNsu: 1678,
            mayAdoptPosition: true,
            blockedUntil: CarbonImmutable::now()->addHour(),
            failure: FiscalFailure::Blocked,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursor($client);

        $this->assertSame('blocked_consumption', $cursor->last_error);
        // A parada continua sendo a mesma das duas, e a posição do corpo da
        // rejeição continua sendo adotada: o rótulo não mexe em nenhum dos
        // dois eixos.
        $this->assertNotNull($cursor->blocked_until);
        $this->assertTrue($cursor->blocked_until->isFuture());
        $this->assertSame(1678, $cursor->last_nsu);
    }

    public function test_a_marca_de_consumo_indevido_nao_depende_da_pausa(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // A classificação e a pausa são eixos independentes, e o design diz que
        // o são: a pausa de uma hora vale nos dois tipos de recusa, e o rótulo é
        // só do consumo indevido. Sem este teste, acrescentar
        // `&& $blockedUntil !== null` à expressão do marcador passaria a suíte
        // inteira — os três fixtures de `Blocked` trazem a pausa junto, e um
        // teste que amarra as duas coisas só consegue provar que as duas existem.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [],
            lastNsu: 1678,
            mayAdoptPosition: true,
            failure: FiscalFailure::Blocked,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursor($client);

        $this->assertSame('blocked_consumption', $cursor->last_error);
        $this->assertNull($cursor->blocked_until);
    }

    public function test_nenhum_documento_localizado_pausa_sem_deixar_marca(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill(['last_nsu' => 900])->save();

        // Mesma pausa, outro motivo: o fisco não tinha nada novo para o CNPJ.
        // A coluna fica limpa, porque um cliente saudável consultando de hora
        // em hora não pode aparecer na lista de atenção.
        //
        // A posição devolvida é 1200 e não a 900 que estava guardada: com as duas
        // iguais, `assertSame(900, …)` passaria tanto se a posição tivesse sido
        // preservada quanto se tivesse sido adotada, e o teste não provaria nada
        // sobre a recusa do serviço.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [],
            lastNsu: 1200,
            mayAdoptPosition: false,
            blockedUntil: CarbonImmutable::now()->addHour(),
            failure: FiscalFailure::NoDocuments,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursor($client);

        $this->assertNull($cursor->last_error);
        $this->assertNotNull($cursor->blocked_until);
        $this->assertSame(900, $cursor->last_nsu);
    }

    public function test_a_marca_de_consumo_indevido_limpa_na_resposta_seguinte(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => $this->batch(
            [],
            lastNsu: 1678,
            mayAdoptPosition: true,
            blockedUntil: CarbonImmutable::now()->addHour(),
            failure: FiscalFailure::Blocked,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertSame('blocked_consumption', $this->cursor($client)->last_error);

        // A janela do fisco vence, e a captura volta a ser consultada.
        $this->travelTo(now()->addHours(2));

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(1800, self::CHAVE_100)],
            lastNsu: 1800,
            mayAdoptPosition: true,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // `last_error` é o último erro, não um histórico: o cliente que voltou
        // a responder não fica marcado como consumo indevido para sempre.
        $cursor = $this->cursor($client);

        $this->assertNull($cursor->last_error);
        $this->assertNull($cursor->blocked_until);
        $this->assertSame(1800, $cursor->last_nsu);
    }

    public function test_records_the_run_before_and_the_sighting_after_the_pull_answers(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursor($client);

        // As duas colunas Saidas de uma captura que deu certo: "rodamos" é
        // verdade mesmo quando a resposta veio vazia, e "vimos" só pode ser
        // verdade depois que a resposta chegou.
        $this->assertNotNull($cursor->last_run_at);
        $this->assertNotNull($cursor->last_seen_at);
        $this->assertNotNull($cursor->last_success_at);
    }

    public function test_records_the_run_but_not_the_sighting_when_the_call_fails(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => throw new FiscalException(
            'Rejeicao: Servico em manutencao. detalhe=H4sIAAAAAAAA',
            FiscalFailure::Upstream,
        ));

        try {
            $this->service()->capture($client, FiscalSource::NfeDistribuicao);
            $this->fail('Uma chamada sem resposta não devolve resultado.');
        } catch (FiscalException) {
            // esperado: quem chamou decide o que fazer com a falha.
        }

        $cursor = $this->cursor($client);

        $this->assertNotNull($cursor->last_run_at);
        $this->assertNull($cursor->last_seen_at);
        $this->assertNull($cursor->last_success_at);
        $this->assertSame(0, $cursor->last_nsu);
    }

    public function test_records_a_bounded_reason_when_the_call_fails(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // A mensagem é escrita pelo serviço: o `faultstring` vem da autoridade, e
        // o validador deste módulo cita na mensagem o valor do elemento que
        // reprovou. `last_error` é lida pelo painel, então entra a classe — que
        // diz a origem — e uma frase curta que diz a etapa.
        $this->bindConnector(fn (): PullResult => throw new FiscalException(
            'Rejeicao: Consumo Indevido. detalhe=<docZip>H4sIAAAAAAAA</docZip>',
            FiscalFailure::Blocked,
        ));

        try {
            $this->service()->capture($client, FiscalSource::NfeDistribuicao);
            $this->fail('Uma chamada sem resposta não devolve resultado.');
        } catch (FiscalException) {
            // esperado
        }

        $error = (string) $this->cursor($client)->last_error;

        $this->assertStringContainsString('FiscalException', $error);
        $this->assertStringContainsString('consulta', $error);
        $this->assertStringNotContainsString('docZip', $error);
        $this->assertStringNotContainsString('H4sIAAAAAAAA', $error);
        $this->assertStringNotContainsString('Consumo Indevido', $error);
        $this->assertLessThanOrEqual(200, mb_strlen($error));
    }

    public function test_records_a_position_ahead_of_the_service_as_its_own_kind(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill(['last_nsu' => 900])->save();

        // Posição à frente do serviço: o ambiente nacional tem menos posições do
        // que o cursor pede. Não é indisponibilidade, e é por isso que precisa
        // de nome próprio na coluna: a reconciliação é o que resolve este
        // cliente, e ela não consegue escolher um cliente se a coluna não diz
        // qual deles é.
        $this->bindConnector(fn (): PullResult => throw new FiscalException(
            'Rejeicao: A posicao enviada e maior que a maior posicao do ambiente nacional.',
            FiscalFailure::CursorAhead,
        ));

        $this->failTheCapture($client);

        $cursor = $this->cursor($client);
        $error = (string) $cursor->last_error;

        $this->assertStringContainsString('cursor_ahead', $error);
        $this->assertStringNotContainsString('upstream', $error);
        $this->assertStringNotContainsString('unauthorized', $error);
        $this->assertStringNotContainsString('maior que a maior', $error);

        // A segunda metade da spec: a posição guardada é o insumo da
        // reconciliação, então ela não é descartada nem reescrita por uma falha
        // de consulta.
        $this->assertSame(900, $cursor->last_nsu);
        $this->assertNull($cursor->last_seen_at);
    }

    public function test_records_a_credential_mismatch_as_its_own_kind(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // CNPJ sem correspondência com o certificado: a credencial é recusada, e
        // recusar de novo não resolve nada. A coluna precisa dizer "credencial",
        // porque a reação é o cliente reenviar o certificado e não o serviço
        // voltar.
        $this->bindConnector(fn (): PullResult => throw new FiscalException(
            'Rejeicao: Certificado invalido para o CNPJ consultado.',
            FiscalFailure::Unauthorized,
        ));

        $this->failTheCapture($client);

        $cursor = $this->cursor($client);
        $error = (string) $cursor->last_error;

        $this->assertStringContainsString('unauthorized', $error);
        $this->assertStringNotContainsString('cursor_ahead', $error);
        $this->assertStringNotContainsString('upstream', $error);
        $this->assertNull($cursor->last_seen_at);
        $this->assertSame(0, $cursor->last_nsu);
    }

    public function test_records_a_transient_outage_as_its_own_kind(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        // O caso que a distinção existe para não confundir: a mesma frase, a mesma
        // classe, a única diferença é que esta se resolve sozinha.
        $this->bindConnector(fn (): PullResult => throw new FiscalException(
            'O servico de distribuicao recusou a chamada: manutencao programada.',
            FiscalFailure::Upstream,
        ));

        $this->failTheCapture($client);

        $error = (string) $this->cursor($client)->last_error;

        $this->assertStringContainsString('upstream', $error);
        $this->assertStringNotContainsString('cursor_ahead', $error);
        $this->assertStringNotContainsString('unauthorized', $error);
        $this->assertStringNotContainsString('manutencao', $error);
    }

    public function test_clears_the_recorded_reason_after_a_capture_that_works(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill(['last_error' => 'FiscalException — falha na consulta.'])->save();

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // A coluna é o "último erro", não um histórico de erros: um cliente
        // saudável que continua aparecendo na lista de atenção do painel é um
        // painel que ninguém lê.
        $this->assertNull($this->cursor($client)->last_error);
    }

    public function test_reports_a_held_lock_apart_from_a_service_block(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->cursor($client)->forceFill(['last_nsu' => 900])->save();

        $this->bindConnector(fn (): PullResult => $this->batch([], 1000, true));

        // Outra execução no ar, para o mesmo cliente e a mesma fonte: a chave é
        // a que o serviço usa, e o bloqueio é do dono da chave, não do tempo.
        $other = Cache::lock($this->lockKey($client), 60);
        $this->assertTrue($other->get());

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // Não é `Blocked`: bloqueio é o fisco mandando parar uma hora este
        // cliente, e é estado do cliente. Chave ocupada é outro trabalho no ar,
        // e dizer o contrário ao operador descreveria um estado que o cliente não
        // tem.
        $this->assertSame([], $this->pulls);
        $this->assertFalse($outcome->ran);
        $this->assertSame(FiscalSkipReason::Locked, $outcome->skipReason);
        $this->assertNotSame(FiscalSkipReason::Blocked, $outcome->skipReason);

        $this->assertSame(900, $outcome->fromNsu);
        $this->assertSame(900, $outcome->toNsu);
        $this->assertSame(900, $this->cursor($client)->last_nsu);
    }

    public function test_releases_the_lock_when_the_capture_fails(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => throw new FiscalException('Indisponivel.', FiscalFailure::Upstream));

        try {
            $this->service()->capture($client, FiscalSource::NfeDistribuicao);
        } catch (FiscalException) {
            // esperado
        }

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            lastNsu: 200,
            mayAdoptPosition: true,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // A chave volta para o poço mesmo com a captura interrompida no meio: um
        // bloqueio vazado seguraria o cliente pelo tempo todo do TTL, uma vez por
        // falha.
        $this->assertTrue($outcome->ran);
        $this->assertCount(2, $this->pulls);
    }

    public function test_the_lock_outlives_the_worker_timeout(): void
    {
        [$client] = $this->tenant(withCertificate: true);

        $this->bindConnector(fn (): PullResult => $this->batch([], 0, true));

        // A fachada trocada captura o TTL que o serviço passou e devolve a
        // trava real do mesmo array store: aquisição, execução e liberação
        // continuam sendo as de produção — o que muda é que o argumento vira
        // observável.
        $store = Cache::store();
        $seconds = null;

        Cache::shouldReceive('lock')->once()->andReturnUsing(
            function (string $name, int $ttl) use ($store, &$seconds): ArrayLock {
                $seconds = $ttl;

                return new ArrayLock($store, $name, $ttl);
            }
        );

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // O TTL é o da configuração, e ele precisa vencer DEPOIS do
        // --timeout do worker: um job morto pelo timeout aos 120 segundos
        // não pode deixar a trava vencida antes, senão a execução seguinte
        // começa enquanto a antiga ainda escreve — a consulta paralela que
        // a NT classifica como uso indevido.
        $this->assertSame((int) config('fiscal.lock_ttl'), $seconds);
        $this->assertGreaterThan($this->workerTimeout(), $seconds);
    }

    public function test_a_skipped_capture_leaves_the_cursor_without_a_run(): void
    {
        [$client] = $this->tenant(withCertificate: false);

        $this->bindConnector(fn (): PullResult => $this->batch([], 0, true));

        $this->assertNull($this->storedCursor($client));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        // A linha do cursor nasce na primeira execução, mesmo quando ela não sai
        // do lugar: é nela que a carteira vai ler a posição de um cliente que
        // ainda não foi capturado.
        $cursor = $this->storedCursor($client);

        $this->assertNotNull($cursor);
        $this->assertSame(0, $cursor->last_nsu);
        $this->assertNull($cursor->last_run_at);
        $this->assertNull($cursor->last_seen_at);
        $this->assertNull($cursor->last_error);
        $this->assertSame($client->account_id, $cursor->account_id);
        $this->assertSame(FiscalSource::NfeDistribuicao, $cursor->source);
    }

    private function service(): FiscalCaptureService
    {
        return resolve(FiscalCaptureService::class);
    }

    /**
     * Roda a captura esperando a falha do conector. A exceção sobe — quem chamou é
     * quem decide se adianta repetir — e o que fica no cursor é o que se verifica
     * depois.
     */
    private function failTheCapture(Client $client): void
    {
        try {
            $this->service()->capture($client, FiscalSource::NfeDistribuicao);
            $this->fail('Uma chamada sem resposta não devolve resultado.');
        } catch (FiscalException) {
            // esperado
        }
    }

    /**
     * Liga um conector falso que registra as chamadas em `$this->pulls` e devolve
     * — ou levanta — o que o teste preparou. O registro fica só com a fonte de
     * NF-e, que é a fonte que estes testes capturam.
     *
     * @param  Closure(): PullResult  $answer
     */
    private function bindConnector(Closure $answer): void
    {
        $pull = function (Client $client, int $fromNsu, int $limit) use ($answer): PullResult {
            $this->pulls[] = ['client' => $client, 'fromNsu' => $fromNsu, 'limit' => $limit];

            return $answer();
        };

        $fake = new class($pull) implements FiscalConnector
        {
            /** @param  Closure(Client, int, int): PullResult  $pull */
            public function __construct(private readonly Closure $pull) {}

            public function source(): FiscalSource
            {
                return FiscalSource::NfeDistribuicao;
            }

            public function pull(Client $client, int $fromNsu, int $limit): PullResult
            {
                return ($this->pull)($client, $fromNsu, $limit);
            }

            public function fetchByChave(Client $client, string $chave): ?PulledDocument
            {
                return null;
            }

            public function fetchByNsu(Client $client, int $nsu): ?PulledDocument
            {
                return null;
            }
        };

        // O dublê entra **pelo registro**, e não por uma ligação da interface
        // `FiscalConnector`: quem fala com o fisco resolve o conector pela fonte,
        // e é o registro que é a fonte dessa resolução.
        $this->app->instance(FiscalConnectorRegistry::class, new FiscalConnectorRegistry([
            FiscalSource::NfeDistribuicao->value => $fake,
        ]));
    }

    /**
     * O lote como o conector o devolve.
     *
     * `mayAdoptPosition` é obrigatório e vem antes dos opcionais de propósito: é
     * a autorização que decide se a posição pode ser gravada, e um teste que a
     * esquecesse passaria com o cursor advanced sem querer.
     *
     * @param  list<PulledDocument>  $documents
     * @param  list<FailedEntry>  $failures
     * @param  FiscalFailure|null  $failure  o rótulo da parada, quando a resposta
     *                                       foi uma rejeição que mandou esperar
     */
    private function batch(
        array $documents,
        int $lastNsu,
        bool $mayAdoptPosition,
        ?CarbonImmutable $blockedUntil = null,
        array $failures = [],
        ?int $maxNsu = null,
        bool $more = false,
        ?FiscalFailure $failure = null,
    ): PullResult {
        return new PullResult(
            documents: $documents,
            lastNsu: $lastNsu,
            maxNsu: $maxNsu,
            more: $more,
            blockedUntil: $blockedUntil,
            mayAdoptPosition: $mayAdoptPosition,
            failures: $failures,
            failure: $failure,
        );
    }

    /** @return array{0: Client} */
    private function tenant(bool $withCertificate): array
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        if ($withCertificate) {
            ClientCertificate::factory()->withPassword()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
            ]);
        }

        return [$client->refresh()];
    }

    /**
     * O reenvio do A1 pelo vault: uma linha nova de certificado, com senha que
     * abre, que passa a ser a corrente do cliente. É o que desfaz a marcação de
     * reenvio, e o que a torna recuperável em vez de definitiva.
     */
    private function reuploadCertificate(Client $client): void
    {
        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
        ]);
    }

    private function cursor(Client $client): FiscalCursor
    {
        $cursor = $this->storedCursor($client);

        if ($cursor !== null) {
            return $cursor;
        }

        $cursor = new FiscalCursor([
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'last_nsu' => 0,
        ]);

        // `account_id` não é mass-assignável nos modelos de tenant do módulo, e
        // o hook de criação o puxaria da conta corrente — que em teste de
        // console não existe. Mesma atribuição que o serviço faz.
        $cursor->account_id = $client->account_id;
        $cursor->save();

        return $cursor;
    }

    private function storedCursor(Client $client): ?FiscalCursor
    {
        return FiscalCursor::query()
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->first();
    }

    private function lockKey(Client $client): string
    {
        return "fiscal:capture:{$client->getKey()}:".FiscalSource::NfeDistribuicao->value;
    }

    /**
     * O --timeout do worker, lido do entrypoint que o define: a leitura é a
     * âncora do teste. Mudar o valor lá sem re-verificar as janelas daqui
     * precisa quebrar o teste — as duas pontas são o mesmo deploy. Gêmeo do
     * helper de mesmo nome no `CaptureFiscalDocumentsCommandTest`.
     */
    private function workerTimeout(): int
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 3).'/docker/queue-entrypoint.sh');

        if ($entrypoint === false || ! preg_match('/--timeout=(\d+)/', $entrypoint, $matches)) {
            $this->fail('--timeout do worker não encontrado em docker/queue-entrypoint.sh.');
        }

        return (int) $matches[1];
    }

    private function pulled(int $nsu, string $chave): PulledDocument
    {
        return new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            stage: FiscalStage::Document,
            chave: $chave,
            eventId: '',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: null,
            nsu: $nsu,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: now()->toImmutable(),
            eventoOcorridoEmAt: null,
            xml: '<resNFe/>',
        );
    }
}
