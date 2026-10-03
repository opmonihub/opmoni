<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A linha `fiscal_manifestations` é o registro de auditoria da manifestação:
 * a spec pede "quem/operação/data" sobre um cliente e uma chave, e a linha
 * responde com `requested_by` (quem pediu) + `event_type` (a operação) +
 * `outcome`/`result_code`/`result_message` (o que o fisco respondeu) +
 * `requested_at`/`sent_at`/`resulted_at` (as três datas do ciclo).
 *
 * Não há gatilho de suporte a audit: a manifestação é automática — o
 * dispatcher a enfileira ao gravar o resumo, e não existe endpoint para um
 * `super_admin` disparar o evento pela UI ou pela API. A "entrada de
 * auditoria de suporte" do design só existiria junto desse endpoint; o campo
 * `requested_by` é a extensão reservada — se um dia houver gatilho humano, o
 * valor muda de `job:...` para o ator.
 *
 * Os testes aqui rodam o fluxo inteiro — captura do resumo, dispatcher, job,
 * conector com `Http::fake` — porque a auditoria provada no fluxo vale mais
 * que a asserção numa linha plantada à mão.
 */
class AuditoriaManifestacaoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35261000000000000191550010000000011123456780';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_a_linha_de_auditoria_carrega_quem_operacao_resultado_e_datas(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();

        $resumo = $this->resumo(100, self::CHAVE);

        $this->documentoResumoGravado($cliente, $resumo);

        Http::fake(['*' => Http::response(
            $this->retEnvEvento('128', 'Lote de Evento Processado', '135', 'Evento registrado e vinculado a NF-e'),
            200,
        )]);

        $this->bindConnector(fn (): PullResult => $this->lote([$resumo]));

        // Fila `sync`: do resumo ao veredito na mesma execução.
        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        $auditoria = FiscalManifestation::withoutGlobalScope('account')->sole();

        // QUEM pediu e para QUEM: a origem do disparo, a conta e o cliente.
        $this->assertSame('job:SendFiscalManifestationJob', $auditoria->requested_by);
        $this->assertSame((int) $cliente->account_id, (int) $auditoria->account_id);
        $this->assertSame($cliente->getKey(), $auditoria->client_id);

        // SOBRE O QUÊ e a OPERAÇÃO: a chave e o evento fiscal.
        $this->assertSame(self::CHAVE, $auditoria->chave_acesso);
        $this->assertSame(FiscalManifestationEventType::CienciaEmissao, $auditoria->event_type);
        $this->assertSame(1, $auditoria->event_seq);

        // O RESULTADO: o veredito e o que o fisco respondeu.
        $this->assertSame(FiscalManifestationOutcome::Sent, $auditoria->outcome);
        $this->assertSame('135', $auditoria->result_code);
        $this->assertSame('Evento registrado e vinculado a NF-e', $auditoria->result_message);

        // As DATAS do ciclo: pedido, ida à rede e veredito, nesta ordem.
        $this->assertNotNull($auditoria->requested_at);
        $this->assertNotNull($auditoria->sent_at);
        $this->assertNotNull($auditoria->resulted_at);
        $this->assertTrue($auditoria->requested_at->lessThanOrEqualTo($auditoria->sent_at));
        $this->assertTrue($auditoria->sent_at->lessThanOrEqualTo($auditoria->resulted_at));
    }

    public function test_o_573_registra_estado_conhecido_mantendo_quem_e_operacao(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();

        $resumo = $this->resumo(100, self::CHAVE);

        $this->documentoResumoGravado($cliente, $resumo);

        // A reentrega do resumo com a ciência já registrada responde `573` —
        // a auditoria diz que a chave já foi manifestada (por quem for), e o
        // fim é o mesmo do `sent`.
        Http::fake(['*' => Http::response(
            $this->retEnvEvento('128', 'Lote de Evento Processado', '573', 'Rejeicao: Duplicidade de Evento'),
            200,
        )]);

        $this->bindConnector(fn (): PullResult => $this->lote([$resumo]));

        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        $auditoria = FiscalManifestation::withoutGlobalScope('account')->sole();

        $this->assertSame('job:SendFiscalManifestationJob', $auditoria->requested_by);
        $this->assertSame($cliente->getKey(), $auditoria->client_id);
        $this->assertSame(self::CHAVE, $auditoria->chave_acesso);
        $this->assertSame(FiscalManifestationEventType::CienciaEmissao, $auditoria->event_type);
        $this->assertSame(FiscalManifestationOutcome::AlreadyManifested, $auditoria->outcome);
        $this->assertSame('573', $auditoria->result_code);
        $this->assertNotNull($auditoria->result_message);
        $this->assertNotNull($auditoria->requested_at);
        $this->assertNotNull($auditoria->sent_at);
        $this->assertNotNull($auditoria->resulted_at);
    }

    public function test_a_auditoria_de_um_pedido_nao_grava_segredo_xml_ou_envelope(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();

        $resumo = $this->resumo(100, self::CHAVE);

        $this->documentoResumoGravado($cliente, $resumo);

        Http::fake(['*' => Http::response(
            $this->retEnvEvento('128', 'Lote de Evento Processado', '135', 'Evento registrado'),
            200,
        )]);

        $this->bindConnector(fn (): PullResult => $this->lote([$resumo]));

        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        // A linha inteira serializada não pode conter a senha do certificado,
        // o XML do evento nem o envelope: a auditoria carrega identificadores
        // e resultado, nunca o que viajou na rede.
        $auditoria = FiscalManifestation::withoutGlobalScope('account')->sole();
        $linha = $auditoria->toJson();

        $this->assertStringNotContainsString('segredo-unico-9f2b', $linha);
        $this->assertStringNotContainsString('<envEvento', $linha);
        $this->assertStringNotContainsString('<evento', $linha);
        $this->assertStringNotContainsString('SignatureValue', $linha);
    }

    /** @return array{0: Client} */
    private function clienteComCertificado(): array
    {
        $account = Account::factory()->create();

        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
        ]);

        ClientCertificate::factory()->withPassword('segredo-unico-9f2b')->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
        ]);

        return [$cliente->refresh()];
    }

    /**
     * O resumo gravado como a captura o grava — o job lê a `emissao_at` dele
     * para medir o prazo de 90 dias da ciência.
     */
    private function documentoResumoGravado(Client $cliente, PulledDocument $resumo): void
    {
        FiscalDocument::factory()->create([
            'account_id' => $cliente->account_id,
            'client_id' => $cliente->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'stage' => FiscalStage::Summary,
            'chave_acesso' => self::CHAVE,
            'emissao_at' => $resumo->emissaoAt,
        ]);
    }

    private function capture(): FiscalCaptureService
    {
        return resolve(FiscalCaptureService::class);
    }

    /**
     * Liga um conector de distribuição falso que devolve o lote preparado —
     * o mesmo padrão do `DispatcherManifestacaoTest`: o dublê entra pelo
     * registro, e nada na captura toca a rede.
     *
     * @param  Closure(): PullResult  $answer
     */
    private function bindConnector(Closure $answer, FiscalSource $source = FiscalSource::NfeDistribuicao): void
    {
        $fake = new class($answer, $source) implements FiscalConnector
        {
            public function __construct(
                private readonly Closure $answer,
                private readonly FiscalSource $source,
            ) {}

            public function source(): FiscalSource
            {
                return $this->source;
            }

            public function pull(Client $client, int $fromNsu, int $limit): PullResult
            {
                return ($this->answer)();
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

        $this->app->instance(FiscalConnectorRegistry::class, new FiscalConnectorRegistry([
            $source->value => $fake,
        ]));
    }

    /**
     * O resumo como a distribuição o entrega.
     */
    private function resumo(int $nsu, string $chave): PulledDocument
    {
        return new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            stage: FiscalStage::Summary,
            chave: $chave,
            eventId: '',
            emitenteCnpj: '99999999000199',
            destinatarioCnpj: '00000000000191',
            valorTotal: '100.00',
            digVal: null,
            nsu: $nsu,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: now()->subDays(5)->toImmutable(),
            eventoOcorridoEmAt: null,
            xml: '<resNFe/>',
        );
    }

    /**
     * @param  list<PulledDocument>  $documents
     */
    private function lote(array $documents): PullResult
    {
        $lastNsu = $documents === [] ? 0 : $documents[array_key_last($documents)]->nsu;

        return new PullResult(
            documents: $documents,
            lastNsu: $lastNsu,
            maxNsu: $lastNsu,
            more: false,
            blockedUntil: null,
            mayAdoptPosition: true,
        );
    }

    /**
     * O `retEnvEvento` do serviço de eventos — o mesmo shape do teste do
     * conector, com o `retEvento` interno opcional.
     */
    private function retEnvEvento(string $cStatLote, string $xMotivoLote, ?string $cStatEvento = null, ?string $xMotivoEvento = null): string
    {
        $retEvento = $cStatEvento === null ? '' : '<retEvento versao="1.00">'
            .'<infEvento>'
            .'<tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatEvento}</cStat><xMotivo>{$xMotivoEvento}</xMotivo>"
            .'<dhRegEvento>2026-10-03T15:00:01-03:00</dhRegEvento>'
            .'</infEvento></retEvento>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeRecepcaoEventoResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/RecepcaoEvento">'
            .'<nfeRecepcaoEventoResult>'
            .'<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            .'<idLote>1</idLote><tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatLote}</cStat><xMotivo>{$xMotivoLote}</xMotivo>"
            .$retEvento
            .'</retEnvEvento></nfeRecepcaoEventoResult>'
            .'</nfeRecepcaoEventoResponse></soap:Body></soap:Envelope>';
    }
}
