<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A orquestração da ciência da emissão: o resumo gravado pela captura enfileira
 * o job, e o job decide — na execução, não no enqueue — se a manifestação sai.
 *
 * Nenhum teste aqui toca a rede: o conector de distribuição é um falso
 * registrado no `FiscalConnectorRegistry`, e o `nfeRecepcaoEvento` é falso por
 * `Http::fake` — `preventStrayRequests` explode se algum byte escapar.
 */
class DispatcherManifestacaoTest extends TestCase
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

    public function test_gravar_um_resumo_enfileira_a_ciencia_da_emissao(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();

        Bus::fake();

        $this->bindConnector(fn (): PullResult => $this->lote([
            $this->resumo(100, self::CHAVE),
        ]));

        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        Bus::assertDispatched(
            SendFiscalManifestationJob::class,
            fn (SendFiscalManifestationJob $job): bool => $job->accountId === (int) $cliente->account_id
                && $job->clientId === (int) $cliente->getKey()
                && $job->chaveAcesso === self::CHAVE,
        );

        $this->assertDatabaseHas('fiscal_manifestations', [
            'client_id' => $cliente->getKey(),
            'chave_acesso' => self::CHAVE,
        ]);
    }

    public function test_gravar_um_documento_completo_nao_enfileira_nada(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();

        Bus::fake();

        // O documento completo de outra chave é o lote do dia a dia que não
        // deve produzir manifestação nenhuma: quem enfileira é o resumo.
        $this->bindConnector(fn (): PullResult => $this->lote([
            $this->documento(100, self::CHAVE),
        ]));

        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        Bus::assertNotDispatched(SendFiscalManifestationJob::class);
        $this->assertDatabaseCount('fiscal_manifestations', 0);
    }

    public function test_com_o_gate_desligado_o_resumo_grava_sem_enfileirar(): void
    {
        config(['fiscal.manifestacao_enabled' => false]);

        [$cliente] = $this->clienteComCertificado();

        Bus::fake();

        $this->bindConnector(fn (): PullResult => $this->lote([
            $this->resumo(100, self::CHAVE),
        ]));

        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        // Gate desligado é o comportamento de antes do change: o resumo entra,
        // a posição anda e nenhum evento é nem pedido.
        $this->assertSame(1, FiscalDocument::count());
        Bus::assertNotDispatched(SendFiscalManifestationJob::class);
        $this->assertDatabaseCount('fiscal_manifestations', 0);
    }

    public function test_o_job_envia_a_ciencia_dentro_do_prazo(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();

        $resumo = $this->resumo(100, self::CHAVE);

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

        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'Lote de Evento Processado', '135', 'Evento registrado e vinculado a NF-e'), 200)]);

        $this->bindConnector(fn (): PullResult => $this->lote([$resumo]));

        // Fila `sync`: o despacho dentro da captura executa o job na hora — o
        // fluxo inteiro, do resumo ao veredito, acontece nesta execução.
        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'RecepcaoEvento')
            && str_contains($request->body(), '<chNFe>'.self::CHAVE.'</chNFe>'));

        $registro = FiscalManifestation::withoutGlobalScope('account')->sole();

        $this->assertSame(FiscalManifestationOutcome::Sent, $registro->outcome);
        $this->assertSame('135', $registro->result_code);
        $this->assertNotNull($registro->sent_at);
        $this->assertNotNull($registro->resulted_at);
    }

    public function test_cliente_em_bloqueio_nao_recebe_evento_na_execucao(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();

        $resumo = $this->resumo(100, self::CHAVE);

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

        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'ok', '135', 'Evento registrado'), 200)]);

        $this->bindConnector(fn (): PullResult => $this->lote([$resumo]));

        // A janela nasce depois do enqueue: a decisão é do job na execução,
        // não do dispatcher no despacho — é por isso que o bloqueio é gravado
        // entre os dois, em vez de antes do lote. `Bus::fake` segura o job na
        // fila para o bloqueio entrar no meio.
        Bus::fake();

        $this->capture()->capture($cliente, FiscalSource::NfeDistribuicao);

        // A captura já gravou o cursor do cliente — a janela nasce sobre ele,
        // não numa linha nova.
        FiscalCursor::query()
            ->where('client_id', $cliente->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->sole()
            ->forceFill(['blocked_until' => now()->addMinutes(30)])
            ->save();

        $job = new SendFiscalManifestationJob(
            accountId: (int) $cliente->account_id,
            clientId: (int) $cliente->getKey(),
            chaveAcesso: self::CHAVE,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $job->handle(
            resolve(FiscalManifestationStore::class),
            resolve(RecepcaoEventoConnector::class),
        );

        // Nenhum byte saiu: cliente bloqueado não recebe evento até a janela
        // vencer — a manifestação espera, e o estado fica pendente de uma
        // passada posterior.
        Http::assertNothingSent();

        $registro = FiscalManifestation::withoutGlobalScope('account')->sole();

        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
        $this->assertNull($registro->sent_at);
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

    private function capture(): FiscalCaptureService
    {
        return resolve(FiscalCaptureService::class);
    }

    /**
     * Liga um conector de distribuição falso que devolve o lote preparado —
     * o mesmo padrão do `FiscalCaptureServiceTest`: o dublê entra pelo
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
     * O resumo como a distribuição o entrega: `stage` é o que o hook consulta.
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

    private function documento(int $nsu, string $chave): PulledDocument
    {
        $resumo = $this->resumo($nsu, $chave);

        return new PulledDocument(
            model: $resumo->model,
            kind: $resumo->kind,
            stage: FiscalStage::Document,
            chave: $resumo->chave,
            eventId: $resumo->eventId,
            emitenteCnpj: $resumo->emitenteCnpj,
            destinatarioCnpj: $resumo->destinatarioCnpj,
            valorTotal: $resumo->valorTotal,
            digVal: $resumo->digVal,
            nsu: $resumo->nsu,
            schema: 'procNFe_v4.00.xsd',
            emissaoAt: $resumo->emissaoAt,
            eventoOcorridoEmAt: $resumo->eventoOcorridoEmAt,
            xml: '<procNFe/>',
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
