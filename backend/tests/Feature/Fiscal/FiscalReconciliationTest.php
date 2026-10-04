<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalFailure;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\FiscalGap;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Capture\FiscalReconciliation;
use App\Services\Fiscal\Contracts\FailedEntry;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A lacuna conhecida e a reconciliação: onde a posição que não virou documento
 * é gravada, e como ela volta para ser buscada uma a uma.
 *
 * Duas metades e uma regra que as atravessa. A captura grava a lacuna — porque
 * `last_error` tem uma linha por lote e a identidade da entrada ilegível é o
 * que faz o buraco recuperável. A reconciliação volta para buscá-la — e ela não
 * anda com a posição em nenhum caminho, nem no sucesso, nem na recusa, nem na
 * exceção. Uma recuperação que mexesse no cursor transformaria o mecanismo de
 * fechar buraco em avanço inventado, e a idempotência do módulo inteiro
 * (lote gravado antes de a posição andar) perderia o chão.
 *
 * Nenhum teste aqui toca a rede: o `FiscalConnector` é um falso ligado no
 * container e `preventStrayRequests` explode se alguma requisição escapar.
 */
class FiscalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaves de acesso com o dígito verificador que o módulo 11 da NT exige —
     * o writer recusa a que não fecha, e a identidade do documento é a chave,
     * não a posição.
     */
    private const CHAVE_100 = '33333333333333333333333333333333333333331007';

    private const CHAVE_101 = '33333333333333333333333333333333333333331015';

    private const CHAVE_102 = '33333333333333333333333333333333333333331023';

    private const CHAVE_999 = '33333333333333333333333333333333333333339997';

    private const CHAVE_QUE_NAO_FECHA = '33333333333333333333333333333333333333332004';

    /**
     * Chaves de 50 posições da NFS-e nacional, com o dígito verificador que o
     * módulo 11 do próprio módulo calcula — a mesma conta de
     * `FiscalXmlMetadata::isValidChave()`, que o writer aplica a toda linha.
     */
    private const CHAVE_NFSE_100 = '35260911222333000181000100000012345678901234567892';

    private const CHAVE_NFSE_101 = '35260911222333000181000100000012345678901234567990';

    private const CHAVE_NFSE_102 = '35260911222333000181000100000012345678901234567000';

    /**
     * As posições pedidas uma a uma, na ordem em que saíram. É a única prova de
     * que a reconciliação foi ao fisco: uma posição que ninguém pediu é um
     * buraco que ninguém fechou, e uma posição pedida a mais é consulta
     * indevida — a mesma coisa que bloqueia o CNPJ.
     *
     * @var list<int>
     */
    private array $lookups = [];

    /**
     * Os conectores falsos já ligados, por fonte, para que a segunda ligação
     * não apague a primeira — o registro é remontado inteiro a cada `bindConnector`.
     *
     * @var array<string, FiscalConnector>
     */
    private array $ligados = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
        Http::preventStrayRequests();

        // Vários testes assertam o gate desligado e ligam dentro do caso: com
        // `*_ENABLED` ligado no `.env` do canário o `assertFalse` inicial já
        // quebrava. O gate é da instalação, não do caso — desligado aqui.
        config(['fiscal.nfse_enabled' => false]);
        config(['fiscal.manifestacao_enabled' => false]);
        config(['fiscal.cte_enabled' => false]);
    }

    public function test_recupera_a_posicao_pendente_e_encerra_a_lacuna(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $gap = $this->createGap($client, 101);

        $this->bindConnector($this->noPull(), fn (): ?PulledDocument => $this->pulled(101, self::CHAVE_101));

        $recovered = $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao);

        $this->assertSame(1, $recovered);
        $this->assertSame([101], $this->lookups);
        $this->assertDatabaseMissing('fiscal_gaps', ['id' => $gap->getKey()]);
        $this->assertSame([101], FiscalDocument::query()->orderBy('nsu')->pluck('nsu')->all());

        // A posição guardada é a posição que a captura incremental soube
        // alcançar, e a reconciliação não a move: `last_nsu`, `last_run_at` e
        // `last_seen_at` continuam exatamente como estavam, porque nada aqui é
        // uma captura.
        $cursor = $this->cursorOf($client);
        $this->assertSame(100, $cursor->last_nsu);
        $this->assertNull($cursor->last_run_at);
        $this->assertNull($cursor->last_seen_at);
        $this->assertNull($cursor->last_success_at);
    }

    public function test_a_segunda_execucao_nao_consulta_nenhuma_posicao(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        $this->bindConnector($this->noPull(), fn (): ?PulledDocument => $this->pulled(101, self::CHAVE_101));

        $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao);
        $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao);

        // A lacuna foi encerrada porque o documento entrou pelo writer, e não
        // porque a posição andou: quem some da fila é a pendência, e a posição
        // segue onde a captura a deixou.
        $this->assertSame([101], $this->lookups);
        $this->assertSame(100, $this->cursorOf($client)->last_nsu);
        $this->assertSame(1, FiscalDocument::count());
    }

    public function test_uma_execucao_estuda_no_maximo_o_teto_horario_de_consultas(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);

        for ($nsu = 101; $nsu <= 125; $nsu++) {
            $this->createGap($client, $nsu);
        }

        $this->bindConnector($this->noPull());

        $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao);

        // O teto por execução é o mesmo número do teto horário do CNPJ, e é
        // por isso que ele vem da configuração: uma execução que estudasse
        // mais posições do que a hora inteira permite gastaria o orçamento
        // inteiro e ainda assim tentaria a vaga seguinte, que não existe.
        $this->assertSame((int) config('fiscal.consulta_hourly_limit'), count($this->lookups));

        // As posições que não entraram na execução não foram cobradas: a
        // tentativa é de consulta que saiu, e nenhuma saiu.
        $this->assertSame(
            25 - (int) config('fiscal.consulta_hourly_limit'),
            FiscalGap::query()->where('attempts', 0)->count(),
        );
    }

    public function test_posicao_sem_documento_conta_uma_tentativa_e_adia_uma_hora(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        // `null` é a resposta do fisco: ele diz que não tem documento naquela
        // posição. Isso é resposta, não falha de transporte, e o que ela
        // autoriza é uma nova tentativa mais tarde — nunca apagar a lacuna.
        $this->bindConnector($this->noPull());

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $gap = $this->gapOf($client, 101);
        $this->assertSame(1, $gap->attempts);
        $this->assertTrue($gap->next_attempt_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame(100, $this->cursorOf($client)->last_nsu);
    }

    public function test_apos_o_teto_de_tentativas_a_posicao_deixa_de_ser_consultada(): void
    {
        config(['fiscal.reconcile_max_attempts' => 2]);

        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        $this->bindConnector($this->noPull());

        for ($run = 1; $run <= 2; $run++) {
            $this->travel(2)->hours();

            $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao);
        }

        $this->assertSame([101, 101], $this->lookups);
        $this->assertSame(2, $this->gapOf($client, 101)->attempts);

        $this->travel(2)->hours();

        $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao);

        // O teto vem da configuração, e é ele que cala a posição: uma posição
        // que o fisco não tem não pode custar uma consulta por execução para
        // sempre, nem quando o teto é maior que o número escrito no código.
        $this->assertSame([101, 101], $this->lookups);
        $this->assertSame(2, $this->gapOf($client, 101)->attempts);
    }

    /**
     * A volta atrás de CT-e com a captura desligada **não vai ao fisco**.
     *
     * A lacuna sobrevive à noite — uma lacuna que ninguém perguntou não pode ser
     * considerada tentada, e é o que a coloca na fila outra vez —, nenhuma
     * `consNSU` sai contra o serviço cujos parâmetros ninguém verificou, e nada
     * do teto horário de consultas é gasto. A linha de log existe porque o
     * silêncio aqui é indistinguível de "não havia buraco": a lacuna voltaria na
     * noite seguinte sem que ninguém entendesse por quê.
     */
    public function test_a_reconciliacao_de_cte_desligada_nao_consulta_e_nao_cobra_tentativa(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101, ['source' => FiscalSource::CteDistribuicao]);

        $this->bindConnector($this->noPull(), source: FiscalSource::CteDistribuicao);

        $this->assertFalse(config('fiscal.cte_enabled'), 'A captura de CT-e precisa nascer desligada.');

        Log::spy();

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::CteDistribuicao));

        $this->assertSame([], $this->lookups, 'Nenhuma consulta por posição pode sair para o serviço de CT-e.');
        $this->assertSame(0, $this->gapOf($client, 101, FiscalSource::CteDistribuicao)->attempts);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.reconciliacao.fonte_pausada'
                && $context['fonte'] === FiscalSource::CteDistribuicao->value
                && $context['client_id'] === $client->getKey()
                && str_contains($context['reason'], 'cte_enabled'));
    }

    /**
     * Com a porta ligada, a volta atrás de CT-e volta atrás como sempre — a
     * guarda é a da instalação, não uma recusa do fisco e não uma fonte
     * desconhecida: a mesma lacuna, a mesma posição e o mesmo documento entram.
     */
    public function test_a_reconciliacao_de_cte_ligada_recupera_a_posicao_pendente(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101, ['source' => FiscalSource::CteDistribuicao]);

        $this->bindConnector(
            $this->noPull(),
            fn (): ?PulledDocument => $this->pulled(101, self::CHAVE_101, FiscalModel::Cte),
            FiscalSource::CteDistribuicao,
        );

        config(['fiscal.cte_enabled' => true]);

        $this->assertSame(1, $this->reconciliation()->run($client, FiscalSource::CteDistribuicao));

        $this->assertSame([101], $this->lookups);
        $this->assertDatabaseMissing('fiscal_gaps', [
            'client_id' => $client->getKey(),
            'source' => FiscalSource::CteDistribuicao->value,
            'nsu' => 101,
        ]);
    }

    /**
     * A volta atrás de NFS-e com a captura desligada **não vai ao fisco**.
     *
     * A mesma pergunta que a de CT-e acima, com a chave que a ADN responde:
     * `fiscal.nfse_enabled` desligada pula a lacuna sem consulta — nenhum GET
     * sai contra a API cujo contrato ainda não foi observado de perto —, sem
     * gastar tentativa e sem segurar a posição. E ela é a resposta única, na
     * mesma linha de `cte_enabled`, com a fonte e a chave nomeadas.
     */
    public function test_a_reconciliacao_de_nfse_desligada_nao_consulta_e_nao_cobra_tentativa(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100, source: FiscalSource::NfseAdn);
        $this->createGap($client, 101, ['source' => FiscalSource::NfseAdn]);

        $this->bindConnector($this->noPull(), source: FiscalSource::NfseAdn);

        $this->assertFalse(config('fiscal.nfse_enabled'), 'A captura de NFS-e precisa nascer desligada.');

        Log::spy();

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfseAdn));

        $this->assertSame([], $this->lookups, 'Nenhuma consulta por posição pode sair para a ADN.');
        $this->assertSame(0, $this->gapOf($client, 101, FiscalSource::NfseAdn)->attempts);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.reconciliacao.fonte_pausada'
                && $context['fonte'] === FiscalSource::NfseAdn->value
                && $context['client_id'] === $client->getKey()
                && str_contains($context['reason'], 'nfse_enabled'));
    }

    /**
     * Com a porta ligada, a volta atrás de NFS-e recupera a posição como
     * sempre: a mesma lacuna, a mesma consulta `fetchByNsu` e o documento do
     * modelo certo entrando pelo writer.
     */
    public function test_a_reconciliacao_de_nfse_ligada_recupera_a_posicao_pendente(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100, source: FiscalSource::NfseAdn);
        $this->createGap($client, 101, ['source' => FiscalSource::NfseAdn]);

        $this->bindConnector(
            $this->noPull(),
            fn (): ?PulledDocument => $this->pulled(101, self::CHAVE_NFSE_101, FiscalModel::Nfse),
            FiscalSource::NfseAdn,
        );

        config(['fiscal.nfse_enabled' => true]);

        $this->assertSame(1, $this->reconciliation()->run($client, FiscalSource::NfseAdn));

        $this->assertSame([101], $this->lookups);
        $this->assertDatabaseMissing('fiscal_gaps', [
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfseAdn->value,
            'nsu' => 101,
        ]);

        $documento = FiscalDocument::query()
            ->where('source', FiscalSource::NfseAdn)
            ->where('nsu', 101)
            ->sole();

        $this->assertSame(FiscalModel::Nfse, $documento->model);
        $this->assertSame(self::CHAVE_NFSE_101, (string) $documento->chave_acesso);
    }

    /**
     * A lacuna pausada não segura a posição de NFS-e: a captura adota a
     * posição que o fisco autorizou mesmo com a posição ilegível registrada,
     * e marca `gap_paused` — a perda adiada, nomeada, e não a perda que
     * cresce com a pausa.
     */
    public function test_a_lacuna_pausada_de_nfse_nao_segura_a_posicao_da_captura(): void
    {
        $client = $this->tenant();

        $this->assertFalse(config('fiscal.nfse_enabled'), 'A volta atrás de NFS-e começa pausada.');

        $this->bindConnector(
            fn (): PullResult => $this->batch(
                [$this->pulled(100, self::CHAVE_NFSE_100, FiscalModel::Nfse), $this->pulled(102, self::CHAVE_NFSE_102, FiscalModel::Nfse)],
                200,
                false,
                failures: [new FailedEntry(101, 'NFSE', 'FiscalXmlMetadata rejeitou o documento decodificado.')],
            ),
            source: FiscalSource::NfseAdn,
        );

        $this->capture()->capture($client, FiscalSource::NfseAdn);

        $cursor = $this->cursorOf($client, FiscalSource::NfseAdn);
        $this->assertSame(200, $cursor->last_nsu);
        $this->assertSame('gap_paused', $cursor->last_error);

        // A linha fica, sem contagem e sem prazo — devida assim que a chave
        // voltar, e nunca uma posição que a captura teria de reentregar para
        // sempre.
        $lacuna = $this->gapOf($client, 101, FiscalSource::NfseAdn);
        $this->assertSame(0, $lacuna->attempts);
        $this->assertNull($lacuna->next_attempt_at);
    }

    /**
     * As duas fontes na mesma noite, com CT-e desligada: a de NF-e é recuperada
     * e a de CT-e fica pendente. Uma pausa de CT-e não pode ser a noite em que
     * uma conta perde a reconciliação de NF-e, que é o caminho de produção.
     */
    public function test_a_desligagem_de_cte_nao_derruba_a_reconciliacao_de_nfe(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);
        $this->createGap($client, 102, ['source' => FiscalSource::CteDistribuicao]);

        $this->bindConnector($this->noPull(), fn (): ?PulledDocument => $this->pulled(101, self::CHAVE_101));
        $this->bindConnector(
            $this->noPull(),
            fn (): ?PulledDocument => $this->pulled(102, self::CHAVE_102, FiscalModel::Cte),
            FiscalSource::CteDistribuicao,
        );

        $this->assertFalse(config('fiscal.cte_enabled'));

        $this->assertSame(1, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame([101], $this->lookups);
        $this->assertSame(0, $this->gapOf($client, 102, FiscalSource::CteDistribuicao)->attempts);
        $this->assertSame(1, FiscalDocument::count());
    }

    /**
     * A lacuna deixada por uma noite de pausa é buscada na primeira noite em que
     * a chave volta, e ela é buscada **mesmo estando abaixo do topo**.
     *
     * A posição do cursor já passou de 100 quando a volta atrás é religada, e é
     * por isso que este teste importa: ele confirma, e não assume, o mecanismo de
     * que o desenho depende. `dueGaps()` filtra por fonte, tentativas e prazo — e
     * não pelo cursor —, e `fetchByNsu` é uma consulta por posição que não depende
     * de onde a captura parou. Sem essas duas propriedades, deixar a lacuna
     * pausada não prender a posição seria perda definitiva em vez de perda
     * adiada, e a troca seria pior nos dois sentidos.
     */
    public function test_a_lacuna_pausada_e_consultada_quando_a_chave_volta_mesmo_abaixo_do_topo(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 200);
        $this->createGap($client, 100, ['source' => FiscalSource::CteDistribuicao]);

        $this->bindConnector(
            $this->noPull(),
            fn (): ?PulledDocument => $this->pulled(100, self::CHAVE_100, FiscalModel::Cte),
            FiscalSource::CteDistribuicao,
        );

        $this->assertFalse(config('fiscal.cte_enabled'), 'A volta atrás de CT-e começa pausada.');

        // A lacuna continua exatamente como a pausa a deixou: sem tentativa e sem
        // prazo, que é o que a torna devida assim que a chave voltar.
        $pausada = $this->gapOf($client, 100, FiscalSource::CteDistribuicao);
        $this->assertSame(0, $pausada->attempts);
        $this->assertNull($pausada->next_attempt_at);

        $this->reconciliation()->run($client, FiscalSource::CteDistribuicao);

        $this->assertSame([], $this->lookups, 'A volta atrás pausada não consulta posição nenhuma.');

        config(['fiscal.cte_enabled' => true]);

        $this->assertSame(1, $this->reconciliation()->run($client, FiscalSource::CteDistribuicao));

        $this->assertSame([100], $this->lookups, 'A lacuna abaixo do topo é consultada assim que a chave volta.');
        $this->assertDatabaseMissing('fiscal_gaps', [
            'client_id' => $client->getKey(),
            'source' => FiscalSource::CteDistribuicao->value,
            'nsu' => 100,
        ]);
        $this->assertSame(1, FiscalDocument::query()
            ->where('source', FiscalSource::CteDistribuicao)
            ->where('nsu', 100)
            ->count());
    }

    public function test_consulta_adiada_nao_cobra_tentativa_nem_encerra_a_lacuna(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 100);
        $this->createGap($client, 101);

        // Adiar é a resposta do limite horário de consultas — ou da trava do
        // próprio CNPJ ocupada por outra reconciliação. Nenhuma das duas é
        // recusa do fisco, e as duas chegam para cá como a mesma coisa.
        $this->bindConnector(
            $this->noPull(),
            function (Client $client, int $nsu): ?PulledDocument {
                if ($nsu === 101) {
                    throw new FiscalLookupDeferred('Limite horário de consultas pontuais atingido.');
                }

                return $this->pulled($nsu, self::CHAVE_100);
            },
        );

        $recovered = $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao);

        $this->assertSame(1, $recovered);
        $this->assertSame([100, 101], $this->lookups);

        // A posição adiada continua pendente e sem contagem: a consulta não
        // saiu, e o que não saiu não é erro da posição.
        $adiada = $this->gapOf($client, 101);
        $this->assertSame(0, $adiada->attempts);
        $this->assertNull($adiada->next_attempt_at);
        $this->assertSame(100, $this->cursorOf($client)->last_nsu);
    }

    public function test_documento_de_outra_posicao_nao_encerra_a_lacuna(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        // A consulta por posição devolve o que o serviço mandou, e o serviço
        // pode mandar a posição vizinha. Gravar isso sob a lacuna pedida
        // arquivaria um documento com a identidade de outro, que é o pior erro
        // possível nesta tabela: some o documento verdadeiro e entra o
        // errado, com a coluna de posição afirmando o que não é.
        $this->bindConnector($this->noPull(), fn (): ?PulledDocument => $this->pulled(999, self::CHAVE_999));

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame(1, $this->gapOf($client, 101)->attempts);
        $this->assertSame(100, $this->cursorOf($client)->last_nsu);
    }

    public function test_falha_sem_resposta_mantem_a_lacuna_e_nao_escreve_a_mensagem_da_excecao(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        // A mensagem é escrita pelo serviço, e ela ecoa o `docZip` que o fisco
        // devolveu. A coluna de tentativas e o log recebem a posição e a classe
        // da exceção; o texto do fisco não entra em lugar nenhum.
        //
        // Indisponibilidade, e não consumo indevido: as duas são `FiscalException`
        // e as duas trazem a mensagem do serviço, mas só esta cobra a tentativa —
        // o bloqueio para a execução e não diz nada sobre a posição.
        $this->bindConnector(
            $this->noPull(),
            fn (): ?PulledDocument => throw new FiscalException(
                'Rejeicao: Servico em Manutencao. detalhe=<docZip>H4sIAAAAAAAA</docZip>',
                FiscalFailure::Upstream,
            ),
        );

        Log::spy();

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame(1, $this->gapOf($client, 101)->attempts);

        $cursor = $this->cursorOf($client);
        $this->assertSame(100, $cursor->last_nsu);
        $this->assertNull($cursor->last_seen_at);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.reconciliacao.lacuna_mantida'
                && $context['nsu'] === 101
                && str_contains($context['reason'], 'FiscalException')
                // O que o fisco escreveu não entra nem na linha do log nem na
                // tentativa: o `docZip` e o detalhe da rejeição são o payload
                // que ele devolveu.
                && ! str_contains(serialize($context), 'docZip')
                && ! str_contains(serialize($context), 'H4sIAAAAAAAA'));
    }

    public function test_consumo_indevido_para_a_execucao_e_grava_a_pausa_sem_cobrar_tentativa(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);
        $this->createGap($client, 102);

        // Consumo indevido na primeira consulta por posição. O fisco mandou parar
        // este CNPJ por uma hora, e a segunda posição não pode virar mais uma
        // `consNSU` para um CNPJ que acabou de dizer para deixá-lo em paz.
        $this->bindConnector(
            $this->noPull(),
            fn (): ?PulledDocument => throw new FiscalException(
                'Rejeicao: Consumo Indevido. detalhe=<docZip>H4sIAAAAAAAA</docZip>',
                FiscalFailure::Blocked,
            ),
        );

        Log::spy();

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        // Uma consulta só: o fisco não respondeu nada sobre a posição 102, e
        // insistir nela seria a mesma falta de escuta que bloqueou o CNPJ.
        $this->assertSame([101], $this->lookups);

        // E nenhuma tentativa foi cobrada. O `656` não é resposta sobre a
        // posição, é recusa do serviço inteiro, e três noites assim esgotavam a
        // lacuna de um cliente com A1 perfeitamente bom — a liberação disparava e
        // a posição era abandonada para sempre sem nunca ter sido perguntada.
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
        $this->assertSame(0, $this->gapOf($client, 102)->attempts);

        // A pausa é gravada, e é a mesma coluna, a mesma janela e a mesma
        // configuração que a captura usa: `blocked_until` é autoritativa para as
        // duas consultas, e uma reconciliação que descobre o bloqueio e o
        // devolvesse em branco deixaria a captura prosseguir para o mesmo fisco
        // bloqueado. A posição, essa, fica como estava.
        $cursor = $this->cursorOf($client);
        $this->assertNotNull($cursor->blocked_until);
        $this->assertTrue($cursor->blocked_until->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame(100, $cursor->last_nsu);
        $this->assertNull($cursor->last_run_at);
        $this->assertNull($cursor->last_seen_at);
        $this->assertNull($cursor->last_success_at);

        // O aviso é o mesmo motivo pela qual a pausa existe: frase fixa e nome da
        // classe, nunca o `xMotivo` nem o `docZip` que o fisco devolveu.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.reconciliacao.consulta_bloqueada'
                && $context['nsu'] === 101
                && $context['client_id'] === $client->getKey()
                && str_contains($context['reason'], 'consumo indevido')
                && ! str_contains(serialize($context), 'docZip'));
    }

    public function test_consulta_que_nao_saiu_para_a_execucao_sem_cobrar_tentativa(): void
    {
        // Cliente sem certificado A1: a pre-flight do conector recusa antes de
        // qualquer byte e antes de gastar a vaga do orçamento, então a consulta
        // por posição nem existe. O conector real é o que levanta a recusa — um
        // falso aqui devolveria um documento e não provaria nada.
        $client = $this->tenant(withCertificate: false);
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        $this->bindConnectorDelegatingLookup($this->noPull());

        Log::spy();

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame([101], $this->lookups);

        // A tentativa é contada de consulta que saiu, e esta não saiu. Cobrar
        // aqui daria três noites de A1 inutilizável como três vereditos do fisco
        // sobre a posição — e o esgotamento libera o cursor, que abandona um
        // documento que estava lá o tempo todo.
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
        $this->assertNull($this->gapOf($client, 101)->next_attempt_at);
        $this->assertSame(100, $this->cursorOf($client)->last_nsu);

        // E o log diz qual recusa foi, que é o que o nome da classe compra: com
        // uma `RuntimeException` genérica o diagnóstico inteiro seria a palavra
        // "RuntimeException", indistinguível de um disco cheio.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.reconciliacao.consulta_nao_enviada'
                && $context['nsu'] === 101
                && $context['tentativas'] === 0
                && str_contains($context['reason'], 'FiscalRequestNotSent'));
    }

    public function test_a_uf_que_nao_existe_na_tabela_nao_cobra_tentativa(): void
    {
        // O materializador do conector precisa de bytes no cofre para chegar até
        // a montagem do envelope, onde a UF é lida, então os dois disco são
        // falsificados antes do certificado ser criado.
        Storage::fake('certificates');
        Storage::fake('local');

        $client = $this->tenant();
        $client->forceFill(['state' => 'XX'])->save();
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        $this->bindConnectorDelegatingLookup($this->noPull());

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame([101], $this->lookups);

        // Defeito de cadastro, não de transporte: a requisição também não saiu,
        // e a posição não tem nada a ver com a sigla que o cliente traz.
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
    }

    public function test_falha_de_transporte_para_a_execucao_sem_cobrar_tentativa(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);
        $this->createGap($client, 102);

        // Tempo esgotado ou DNS fora. `ConnectionException` desce de
        // `HttpClientException`, que é `Exception` e não `RuntimeException`:
        // ela escapava do `run()`, escapava do job e virava linha em
        // `failed_jobs` — as lacunas restantes do cliente ficavam sem nenhuma
        // explicação para ninguém.
        $this->bindConnector(
            $this->noPull(),
            fn (): ?PulledDocument => throw new ConnectionException('Connection timed out.'),
        );

        Log::spy();

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame([101], $this->lookups);

        // A mesma regra das demais: nada respondeu sobre a posição, e nada é
        // cobrado. Uma noite de DNS fora seria três tentativas em cada lacuna da
        // carteira, e três tentativas liberam o cursor.
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
        $this->assertSame(0, $this->gapOf($client, 102)->attempts);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.reconciliacao.consulta_sem_resposta'
                && $context['nsu'] === 101
                && $context['tentativas'] === 0
                && str_contains($context['reason'], 'ConnectionException'));
    }

    public function test_cliente_dentro_da_janela_de_bloqueio_nao_e_consultado(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100, ['blocked_until' => now()->addMinutes(30)]);
        $this->createGap($client, 101);

        $this->bindConnector($this->noPull());

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        // O fisco mandou parar uma hora para este CNPJ, e a reconciliação é
        // consulta como qualquer outra: a parada é do cliente, não do módulo.
        $this->assertSame([], $this->lookups);
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
    }

    public function test_historico_interrompido_nao_e_consultado(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100, [
            'last_seen_at' => now()->subDays((int) config('fiscal.continuity_days') + 1),
        ]);
        $this->createGap($client, 101);

        $this->bindConnector($this->noPull());

        $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        // O fisco não gera posições retroativas para o período que ficou de
        // fora, então consultar de novo não recupera nada — e gasta orçamento
        // do CNPJ para descobrir o que a captura já sabe.
        $this->assertSame([], $this->lookups);
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
    }

    public function test_lacuna_de_outra_conta_nao_e_consultada(): void
    {
        $client = $this->tenant();
        $estranha = $this->tenant();
        $this->assertNotSame($client->account_id, $estranha->account_id);

        $this->cursor($client, 100);
        $this->createGap($client, 101);
        $lacunaEstranha = $this->createGap($estranha, 101);

        $this->bindConnector($this->noPull(), fn (Client $client, int $nsu): ?PulledDocument => $this->pulled($nsu, self::CHAVE_101));

        $this->assertSame(1, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame([101], $this->lookups);

        // A carteira é isolada por conta, e a lacuna de outro escritório não é
        // recuperável por este: a mesma posição em outro CNPJ é outro
        // documento.
        $intacta = FiscalGap::withoutGlobalScope('account')->findOrFail($lacunaEstranha->getKey());
        $this->assertSame(0, $intacta->attempts);
        $this->assertSame($estranha->account_id, $intacta->account_id);
    }

    public function test_conta_corrente_residua_nao_esconde_a_lacuna_do_cliente(): void
    {
        $client = $this->tenant();
        $estranha = $this->tenant();

        $this->cursor($client, 100);
        $lacunaDoCliente = $this->createGap($client, 101);
        $lacunaEstranha = $this->createGap($estranha, 101);

        // O worker de fila é longo e a conta corrente é um singleton que ninguém
        // zera entre jobs: o job anterior pode ter deixado a conta de outro
        // cliente apontada aqui. Quem manda na lacuna é o cliente da chamada,
        // nunca o resíduo do ambiente.
        resolve(CurrentTenant::class)->accountId = $estranha->account_id;

        // A dona de cada linha é a conta do cliente, e a coluna não é
        // mass-assignável em `FiscalGap`: `recordGap()` escreve por atribuição
        // direta de propósito, porque um `fill()` ou um `firstOrCreate()` ali
        // deixaria a chave de fora e o hook de criação puxaria a conta corrente —
        // que é exatamente o resíduo que este teste monta. A lacuna passaria a
        // ser de outra conta, em silêncio, e ninguém veria.
        //
        // A leitura é antes da execução porque a execução recupera a posição e
        // apaga a linha: o que se quer fixar é quem é a dona dela, e a dona não
        // muda por a linha deixar de existir.
        $this->assertSame(
            $client->account_id,
            FiscalGap::withoutGlobalScope('account')->findOrFail($lacunaDoCliente->getKey())->account_id,
        );
        $this->assertSame(
            $estranha->account_id,
            FiscalGap::withoutGlobalScope('account')->findOrFail($lacunaEstranha->getKey())->account_id,
        );

        $this->bindConnector($this->noPull(), fn (Client $client, int $nsu): ?PulledDocument => $this->pulled($nsu, self::CHAVE_101));

        $this->assertSame(1, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));

        $this->assertSame(0, FiscalGap::withoutGlobalScope('account')->findOrFail($lacunaEstranha->getKey())->attempts);
    }

    public function test_a_trava_ocupada_da_captura_impede_a_reconciliacao(): void
    {
        $client = $this->tenant();
        $this->cursor($client, 100);
        $this->createGap($client, 101);

        $this->bindConnector($this->noPull());

        // A mesma chave que a captura usa: duas consultas do mesmo CNPJ ao mesmo
        // tempo é a condição prevista que a trava existe para impedir.
        $outraExecucao = Cache::lock($this->captureLockKey($client), 60);
        $this->assertTrue($outraExecucao->get(), 'O teste precisa segurar a trava que a captura usa.');

        try {
            $this->assertSame(0, $this->reconciliation()->run($client, FiscalSource::NfeDistribuicao));
        } finally {
            $outraExecucao->release();
        }

        $this->assertSame([], $this->lookups);
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
    }

    public function test_entrada_ilegivel_registra_uma_lacuna_e_mantem_o_que_entrou(): void
    {
        $client = $this->tenant();

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(102, self::CHAVE_102)],
            200,
            false,
            failures: [new FailedEntry(101, 'resNFe_v1.01.xsd', 'DocZipDecoder não decodificou o payload comprimido.')],
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // Uma posição ilegível é um buraco a reconciliar, não o fim da fila: os
        // documentos dos dois lados dela continuam gravados, e a única lacuna
        // é a posição que o conector recusou.
        $this->assertSame([100, 102], FiscalDocument::query()->orderBy('nsu')->pluck('nsu')->all());
        $this->assertSame([101], $this->gapNsus($client));
    }

    public function test_posicao_ausente_no_meio_do_lote_nao_e_lacuna(): void
    {
        $client = $this->tenant();

        // Duas posições vistas, uma no meio ausente, e a posição devolvida bem
        // acima da última entrada entregue.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(102, self::CHAVE_102)],
            200,
            true,
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // A distância entre 100 e 102 não é lacuna, e nem as duas pontas: 1 a 99
        // antes da primeira posição vista, e 103 a 200 depois da última. A
        // posição do ambiente nacional é maior que a última entrada entregue
        // porque o serviço entrega o que pertence ao CNPJ consultado, e o que
        // está entre duas posições suas pertence a outro contribuinte. Uma
        // primeira captura em 100, 5000 e 200000 "acharia" 4 899 posições que
        // não são documento nenhum, e cada uma custaria uma consulta por hora ao
        // CNPJ para o fisco responder "não há documento nesta posição".
        $this->assertSame([], $this->gapNsus($client));
        $this->assertSame([100, 102], FiscalDocument::query()->orderBy('nsu')->pluck('nsu')->all());

        // E a posição anda, porque o lote entrou inteiro: a spec pede para
        // reconciliar **aquele** documento, e aqui o fisco não disse que existe
        // documento em 101.
        $this->assertSame(200, $this->cursorOf($client)->last_nsu);
        $this->assertNull($this->cursorOf($client)->last_error);
    }

    public function test_lacuna_nao_esgotada_impede_a_posicao_de_andar(): void
    {
        $client = $this->tenant();

        $this->createGap($client, 101);

        // O serviço entregou a posição 101 e a entrada não pôde ser lida. É a
        // única forma de uma lacuna nascer, e a garantia que a captura depende:
        // enquanto a reconciliação não respondeu, a posição não anda.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(102, self::CHAVE_102)],
            200,
            true,
            failures: [new FailedEntry(101, 'resNFe_v1.01.xsd', 'DocZipDecoder não decodificou o payload comprimido.')],
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // A autorização do serviço (200) é uma condição e a integridade do lote
        // é a outra, e a segunda manda: gravar 200 pediria ao fisco o que vem
        // depois da posição ilegível para sempre, e a consulta pontual da
        // reconciliação é a única coisa que ainda sabe onde ela está.
        $cursor = $this->cursorOf($client);
        $this->assertSame(0, $cursor->last_nsu);
        $this->assertSame('lote incompleto: 1 de 3 posições não gravadas.', $cursor->last_error);
    }

    public function test_lacuna_esgotada_libera_a_posicao_do_cliente(): void
    {
        $maximo = (int) config('fiscal.reconcile_max_attempts');
        $client = $this->tenant();

        $this->createGap($client, 101, ['attempts' => $maximo]);

        // Esta é a combinação que `NfeDistributionConnector::collect()` produz
        // de verdade: entrada ilegível no lote significa `mayAdoptPosition`
        // falso, e uma lacuna nasce justamente de uma entrada ilegível. Com a
        // autorização ligada, o teste passaria sem exercitar a liberação.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(102, self::CHAVE_102)],
            200,
            false,
            failures: [new FailedEntry(101, 'resNFe_v1.01.xsd', 'DocZipDecoder não decodificou o payload comprimido.')],
        ));

        Log::spy();

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // A posição já gastou as tentativas configuradas, e a spec manda parar
        // depois delas: parar de consultar não pode virar parar de capturar.
        // Três "não há documento nesta posição" com uma hora de intervalo
        // resolvem tudo o que este serviço resolve sobre aquela posição, e
        // recusar o avanço depois dela perde todos os documentos que estão
        // adiante sem nenhuma chance de recuperá-los.
        $cursor = $this->cursorOf($client);
        $this->assertSame(200, $cursor->last_nsu);

        // A liberação grava o abandono. Um cursor saudável, com `last_error`
        // nulo e documento chegando todo dia, é indistinguível de um cliente que
        // nunca perdeu nada — e a posição que o fisco entregou e ninguém guardou
        // só existe como uma linha de aviso e uma linha de `fiscal_gaps` que
        // nada consulta. Um token fixo é o que torna o estado nomeável, e é o
        // mesmo vocabulário de `certificate_reupload` e `blocked_consumption`.
        $this->assertSame('gap_abandoned', $cursor->last_error);

        // A linha continua: é o registro do que o fisco respondeu, e apagar a
        // posição perderia a única evidência de que houve uma pergunta.
        $gap = $this->gapOf($client, 101);
        $this->assertSame($maximo, $gap->attempts);

        // E a liberação é avisada, com frase fixa e sem nada do fisco. O `once`
        // fica de fora porque a entrada ilegível do mesmo lote também avisa, e
        // o que importa aqui é o conteúdo da linha da liberação.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.capture.lacuna_esgotada'
                && $context['nsu'] === 101
                && $context['client_id'] === $client->getKey()
                && ! str_contains(serialize($context), 'docZip'));
    }

    public function test_lacuna_abaixo_do_topo_segura_a_posicao_com_recusa_do_fisco(): void
    {
        $client = $this->tenant();

        $this->createGap($client, 101);

        // Exatamente a forma do teste de liberação, com uma diferença só: a
        // lacuna ainda tem tentativas. Sem esta diferença, a liberação seria
        // indistinguível de um afrouxamento geral de `mayAdoptPosition` — e é a
        // posição que o fisco não autorizou que está em jogo nos dois.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(102, self::CHAVE_102)],
            200,
            false,
            failures: [new FailedEntry(101, 'resNFe_v1.01.xsd', 'DocZipDecoder não decodificou o payload comprimido.')],
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursorOf($client);
        $this->assertSame(0, $cursor->last_nsu);
        $this->assertSame('lote incompleto: 1 de 3 posições não gravadas.', $cursor->last_error);
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
    }

    public function test_a_liberacao_nao_alcanca_a_recusa_sem_entrada_recusada(): void
    {
        $maximo = (int) config('fiscal.reconcile_max_attempts');
        $client = $this->tenant();

        $this->createGap($client, 100, ['attempts' => $maximo]);

        // O lote tem uma entrada ilegível para o writer, nenhuma recusada pelo
        // conector, e a posição recusada assim mesmo. É a forma do "nenhum
        // documento localizado": o conector nega a posição e o que a resposta
        // traz é o eco da posição pedida.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_QUE_NAO_FECHA)],
            100,
            false,
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // A liberação exige que a recusa do conector seja sobre entradas deste
        // lote. Sem essa exigência, uma lacuna esgotada viraria permissão para
        // adotar o eco da posição pedida — e sobrescrever o cursor com o valor
        // anterior apagaria a posição que a consulta anterior conquistou.
        $this->assertSame(0, $this->cursorOf($client)->last_nsu);
    }

    public function test_as_duas_contas_da_reconciliacao_somam_no_mesmo_lote(): void
    {
        $maximo = (int) config('fiscal.reconcile_max_attempts');
        $client = $this->tenant();

        $this->createGap($client, 101, ['attempts' => $maximo]);
        $this->createGap($client, 102, ['attempts' => $maximo]);
        $this->createGap($client, 103, ['attempts' => 0]);

        // Três posições entregues e ilegíveis, duas resolvidas e uma não. As
        // duas contas viajam no mesmo lote, e é por isso que a frase de
        // `last_error` observa as duas de uma vez só.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [],
            110,
            false,
            failures: [
                new FailedEntry(101, 'resNFe_v1.01.xsd', 'FiscalXmlMetadata rejeitou o documento decodificado.'),
                new FailedEntry(102, 'resNFe_v1.01.xsd', 'FiscalXmlMetadata rejeitou o documento decodificado.'),
                new FailedEntry(103, 'resNFe_v1.01.xsd', 'FiscalXmlMetadata rejeitou o documento decodificado.'),
            ],
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // Uma posição pendente continua segurando a posição do cliente, e as
        // duas esgotadas não entram na conta. A contagem da frase é a
        // observação externa das duas contas ao mesmo tempo: se elas
        // trocassem de lugar, a frase seria "2 de 3" e a liberação do teste
        // anterior deixaria de fazer sentido.
        $cursor = $this->cursorOf($client);
        $this->assertSame(0, $cursor->last_nsu);
        $this->assertSame('lote incompleto: 1 de 3 posições não gravadas.', $cursor->last_error);

        // E nenhuma das três linhas foi mexida: a reencontrada continua com a
        // contagem que tinha, e as duas esgotadas continuam com a delas.
        $this->assertSame($maximo, $this->gapOf($client, 101)->attempts);
        $this->assertSame($maximo, $this->gapOf($client, 102)->attempts);
        $this->assertSame(0, $this->gapOf($client, 103)->attempts);
    }

    public function test_toda_posicao_entregue_e_nao_lida_vira_lacuna(): void
    {
        // Um `batch_limit` de 1 e três entradas ilegíveis: o que a captura
        // grava não tem nada a ver com o tamanho do lote. O `pull()` do
        // conector trata o limite como informativo — o fisco não aceita
        // parametrizar o tamanho do lote — e `collect()` percorre todas as
        // entradas da resposta, sem fatiar nada.
        config(['fiscal.batch_limit' => 1]);

        $client = $this->tenant();

        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100)],
            110,
            false,
            failures: [
                new FailedEntry(101, 'resNFe_v1.01.xsd', 'FiscalXmlMetadata rejeitou o documento decodificado.'),
                new FailedEntry(102, 'resNFe_v1.01.xsd', 'FiscalXmlMetadata rejeitou o documento decodificado.'),
                new FailedEntry(103, 'resNFe_v1.01.xsd', 'FiscalXmlMetadata rejeitou o documento decodificado.'),
            ],
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // Uma linha para cada posição que o serviço entregou e que não entrou.
        // Havia um teto aqui, e ele existia para conter as posições que a
        // varredura de sequência fabricava — que não existem mais. Sobrando
        // para outro uso, o teto produzia uma posição pendente **sem linha**,
        // e uma posição sem linha não tem como esgotar as tentativas, porque
        // esgotar é propriedade da linha: era o travamento que a liberação veio
        // desfazer, voltando pela porta do teto.
        $this->assertSame([101, 102, 103], $this->gapNsus($client));

        $cursor = $this->cursorOf($client);
        $this->assertSame(0, $cursor->last_nsu);
        $this->assertSame('lote incompleto: 3 de 4 posições não gravadas.', $cursor->last_error);
    }

    public function test_gravacao_recusada_registra_a_posicao_da_entrada(): void
    {
        $client = $this->tenant();

        // A chave que não fecha é a recusa real do writer: ele levanta
        // `RuntimeException` antes de gravar qualquer coisa.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_QUE_NAO_FECHA)],
            100,
            true,
        ));

        $outcome = $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // A posição que chegou como documento e não entrou é lacuna do mesmo
        // jeito que a posição que o conector recusou: o que a reconciliação
        // recebe é a posição, e não a origem da recusa.
        $this->assertSame(0, $outcome->stored);
        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame([100], $this->gapNsus($client));
        $this->assertSame(0, $this->cursorOf($client)->last_nsu);
    }

    public function test_a_lacuna_ja_conhecida_nao_perde_a_conta_de_tentativas(): void
    {
        $client = $this->tenant();

        $proxima = now()->subDay()->startOfSecond();
        $this->createGap($client, 101, ['attempts' => 2, 'next_attempt_at' => $proxima]);

        // A captura reencontra a posição porque o serviço a entregou de novo e
        // ela não pôde ser lida de novo — que é como uma lacuna reencontrada
        // acontece na prática.
        $this->bindConnector(fn (): PullResult => $this->batch(
            [$this->pulled(100, self::CHAVE_100), $this->pulled(102, self::CHAVE_102)],
            102,
            false,
            failures: [new FailedEntry(101, 'resNFe_v1.01.xsd', 'DocZipDecoder não decodificou o payload comprimido.')],
        ));

        $this->capture()->capture($client, FiscalSource::NfeDistribuicao);

        // A captura reentrega o mesmo lote enquanto a lacuna estiver aberta, e
        // reencontrar o buraco não pode zerar o histórico de tentativas: a
        // posição que já gastou duas das três tentativas voltaria a ter três
        // vidas a cada hora.
        $gap = $this->gapOf($client, 101);
        $this->assertSame(2, $gap->attempts);
        $this->assertTrue($gap->next_attempt_at->equalTo($proxima));
    }

    private function reconciliation(): FiscalReconciliation
    {
        return resolve(FiscalReconciliation::class);
    }

    private function capture(): FiscalCaptureService
    {
        return resolve(FiscalCaptureService::class);
    }

    /**
     * O lote como o conector o devolve, e a resposta de uma consulta por
     * posição que o fisco não localiza.
     */
    private function noPull(): Closure
    {
        return fn (): PullResult => $this->batch([], 0, false);
    }

    /**
     * Liga um conector falso que registra as consultas por posição em
     * `$this->lookups` e devolve — ou levanta — o que o teste preparou. O
     * registro fica só com a fonte de NF-e, que é a fonte destes testes.
     *
     * @param  Closure(): PullResult  $pull
     * @param  Closure(Client, int): ?PulledDocument|null  $fetchByNsu  quando
     *                                                                  nulo, toda consulta por posição responde que não há documento
     * @param  FiscalSource  $source  a fonte que o dublê serve, para o registro resolver por ela
     */
    private function bindConnector(
        Closure $pull,
        ?Closure $fetchByNsu = null,
        FiscalSource $source = FiscalSource::NfeDistribuicao,
    ): void {
        $answer = $fetchByNsu ?? fn (): ?PulledDocument => null;

        $lookup = function (Client $client, int $nsu) use ($answer): ?PulledDocument {
            $this->lookups[] = $nsu;

            return $answer($client, $nsu);
        };

        $fake = new class($pull, $lookup, $source) implements FiscalConnector
        {
            /**
             * @param  Closure(): PullResult  $pull
             * @param  Closure(Client, int): ?PulledDocument  $fetchByNsu
             */
            public function __construct(
                private readonly Closure $pull,
                private readonly Closure $fetchByNsu,
                private readonly FiscalSource $source,
            ) {}

            public function source(): FiscalSource
            {
                return $this->source;
            }

            public function pull(Client $client, int $fromNsu, int $limit): PullResult
            {
                return ($this->pull)();
            }

            public function fetchByChave(Client $client, string $chave): ?PulledDocument
            {
                return null;
            }

            public function fetchByNsu(Client $client, int $nsu): ?PulledDocument
            {
                return ($this->fetchByNsu)($client, $nsu);
            }
        };

        // O dublê entra pelo registro, e não por uma ligação da interface
        // `FiscalConnector`: quem fala com o fisco resolve o conector pela fonte,
        // e é o registro que é a fonte dessa resolução.
        //
        // O registro é remontado a cada ligação e carrega os que já estavam: um
        // teste que precisa das duas fontes na mesma noite liga as duas, e o
        // segundo dublê não pode apagar o primeiro.
        $this->ligados[$source->value] = $fake;

        $this->app->instance(FiscalConnectorRegistry::class, new FiscalConnectorRegistry($this->ligados));
    }

    /**
     * Liga um conector falso cuja consulta por posição é a do conector real.
     *
     * A pre-flight mora no conector e não na reconciliação, então é o conector
     * que tem de recusar para o laço de recuperação ter o que tratar: um falso
     * que devolvesse documento não provaria nada.
     *
     * @param  Closure(): PullResult  $pull
     */
    private function bindConnectorDelegatingLookup(Closure $pull): void
    {
        $this->bindConnector(
            $pull,
            fn (Client $client, int $nsu): ?PulledDocument => resolve(NfeDistributionConnector::class)->fetchByNsu($client, $nsu),
        );
    }

    /**
     * @param  list<PulledDocument>  $documents
     * @param  list<FailedEntry>  $failures
     */
    private function batch(
        array $documents,
        int $lastNsu,
        bool $mayAdoptPosition,
        array $failures = [],
        ?CarbonImmutable $blockedUntil = null,
    ): PullResult {
        return new PullResult(
            documents: $documents,
            lastNsu: $lastNsu,
            maxNsu: null,
            more: false,
            blockedUntil: $blockedUntil,
            mayAdoptPosition: $mayAdoptPosition,
            failures: $failures,
        );
    }

    private function tenant(bool $withCertificate = true): Client
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        if ($withCertificate) {
            ClientCertificate::factory()->withPassword()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
            ]);
        }

        return $client->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function cursor(
        Client $client,
        int $lastNsu,
        array $attributes = [],
        FiscalSource $source = FiscalSource::NfeDistribuicao,
    ): FiscalCursor {
        $cursor = FiscalCursor::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'source' => $source,
            'last_nsu' => $lastNsu,
        ]);

        $cursor->forceFill($attributes)->save();

        return $cursor;
    }

    private function cursorOf(Client $client, FiscalSource $source = FiscalSource::NfeDistribuicao): FiscalCursor
    {
        return FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('client_id', $client->getKey())
            ->where('source', $source)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createGap(Client $client, int $nsu, array $attributes = []): FiscalGap
    {
        return FiscalGap::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'nsu' => $nsu,
            ...$attributes,
        ]);
    }

    /**
     * A lacuna relida depois da execução, com o escopo de conta fora porque o
     * teste é sobre o que ficou gravado, e a conta corrente é uma condição que
     * o próprio teste controla.
     */
    private function gapOf(Client $client, int $nsu, FiscalSource $source = FiscalSource::NfeDistribuicao): FiscalGap
    {
        return FiscalGap::withoutGlobalScope('account')
            ->where('client_id', $client->getKey())
            ->where('source', $source)
            ->where('nsu', $nsu)
            ->firstOrFail();
    }

    /**
     * As posições pendentes do cliente, em ordem. A leitura ignora o escopo de
     * conta de propósito: é o teste que afirma o que existe, e ele existe
     * mesmo com a conta corrente apontada para outro lugar.
     *
     * @return list<int>
     */
    private function gapNsus(Client $client): array
    {
        return FiscalGap::withoutGlobalScope('account')
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->orderBy('nsu')
            ->pluck('nsu')
            ->all();
    }

    private function captureLockKey(Client $client): string
    {
        return "fiscal:capture:{$client->getKey()}:".FiscalSource::NfeDistribuicao->value;
    }

    private function pulled(int $nsu, string $chave, FiscalModel $model = FiscalModel::Nfe): PulledDocument
    {
        return new PulledDocument(
            model: $model,
            kind: FiscalKind::Document,
            stage: FiscalStage::Document,
            chave: $chave,
            eventId: '',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: null,
            nsu: $nsu,
            schema: match ($model) {
                FiscalModel::Nfe => 'resNFe_v1.01.xsd',
                FiscalModel::Cte => 'procCTe_v4.00.xsd',
                default => 'NFSE',
            },
            emissaoAt: now()->toImmutable(),
            eventoOcorridoEmAt: null,
            xml: '<resNFe/>',
        );
    }
}
