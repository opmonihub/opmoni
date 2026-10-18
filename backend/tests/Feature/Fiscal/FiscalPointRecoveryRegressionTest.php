<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Jobs\RecoverFiscalXmlJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Processing\FiscalProcessingCoordinator;
use App\Services\Fiscal\Processing\FiscalXmlPointRecovery;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\BusinessTestCase;

class FiscalPointRecoveryRegressionTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('fiscal');
        Bus::fake();
        config(['fiscal.manifestacao_enabled' => true, 'fiscal.manifestacao_rollout_mode' => 'fleet', 'fiscal.manifestacao_resync_max_attempts' => 3]);
    }

    public function test_inbound_recovery_rejects_xml_whose_recipient_is_another_client(): void
    {
        [$client, $summary] = $this->scenario('00000000000191');
        $connector = $this->connector('document');
        $result = $this->recovery($connector)->recover($client, $connector->document->chave);
        $this->assertSame('invalid_response', $result);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame('awaiting_xml', $summary->fresh()->processing_state->value);
    }

    public function test_recipient_xml_completes_the_pending_note(): void
    {
        [$client, $summary] = $this->scenario('11222333000181');
        $connector = $this->connector('document');
        $this->assertSame('recovered', $this->recovery($connector)->recover($client, $connector->document->chave));
        $this->assertSame('complete', $summary->fresh()->processing_state->value);
    }

    public function test_budget_deferrals_do_not_consume_provider_attempts(): void
    {
        [$client, $summary] = $this->scenario('11222333000181');
        $connector = $this->connector('deferred');
        $recovery = $this->recovery($connector);
        $job = new RecoverFiscalXmlJob($client->account_id, $client->id, $summary->chave_acesso);
        for ($i = 0; $i < 3; $i++) {
            $job->handle($recovery, resolve(FiscalProcessingCoordinator::class));
        }
        $connector->mode = 'not_found';
        $job->handle($recovery, resolve(FiscalProcessingCoordinator::class));
        $this->assertSame(1, $summary->fresh()->processing_attempts);
        $this->assertSame('deferred', $summary->fresh()->processing_state->value);
    }

    public function test_unusable_certificate_enters_attention_without_consuming_a_provider_attempt(): void
    {
        [$client, $summary] = $this->scenario('11222333000181');
        $connector = $this->connector('not_sendable');
        (new RecoverFiscalXmlJob($client->account_id, $client->id, $summary->chave_acesso))
            ->handle($this->recovery($connector), resolve(FiscalProcessingCoordinator::class));
        $this->assertSame('attention', $summary->fresh()->processing_state->value);
        $this->assertSame('certificate_missing', $summary->fresh()->processing_reason->value);
        $this->assertSame(0, $summary->fresh()->processing_attempts);
    }

    private function scenario(string $taxId): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $client = Client::factory()->company()->create(['account_id' => $account->id, 'tax_id' => $taxId]);
        $summary = FiscalDocument::factory()->summary()->create(['client_id' => $client->id, 'chave_acesso' => '35220499999999999999550010020000001240556600', 'processing_state' => 'awaiting_xml']);

        return [$client, $summary];
    }

    private function connector(string $mode): RecoveryProviderStub
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml'));
        $metadata = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);
        $document = new PulledDocument($metadata->model, $metadata->kind, $metadata->stage, $metadata->chave, $metadata->eventId, $metadata->emitenteCnpj, $metadata->destinatarioCnpj, $metadata->valorTotal, $metadata->digVal, 123, 'procNFe_v4.00.xsd', $metadata->emissaoAt, $metadata->eventoOcorridoEmAt, $xml, authorizedAt: $metadata->authorizedAt);

        return new RecoveryProviderStub($mode, $document);
    }

    private function recovery(RecoveryProviderStub $connector): FiscalXmlPointRecovery
    {
        return new FiscalXmlPointRecovery(new FiscalConnectorRegistry(['nfe_distribuicao' => $connector]), resolve(FiscalDocumentWriter::class));
    }
}

class RecoveryProviderStub implements FiscalConnector
{
    public function __construct(public string $mode, public PulledDocument $document) {}

    public function source(): FiscalSource
    {
        return FiscalSource::NfeDistribuicao;
    }

    public function pull(Client $client, int $fromNsu, int $limit): PullResult
    {
        throw new \RuntimeException('Unused incremental capture');
    }

    public function fetchByNsu(Client $client, int $nsu): ?PulledDocument
    {
        throw new \RuntimeException('Unused NSU lookup');
    }

    public function fetchByChave(Client $client, string $chave): ?PulledDocument
    {
        if ($this->mode === 'not_sendable') {
            throw new FiscalRequestNotSent('Certificate unavailable');
        }
        if ($this->mode === 'deferred') {
            throw new FiscalLookupDeferred('Budget unavailable');
        }

        return $this->mode === 'document' ? $this->document : null;
    }
}
