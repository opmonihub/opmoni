<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Jobs\RecoverFiscalXmlJob;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\ManifestationEventResponseCorrelator;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use App\Services\Fiscal\Processing\FiscalProcessingCoordinator;
use App\Services\Fiscal\Processing\FiscalProcessingDueWork;
use App\Services\Fiscal\Read\FiscalClients;
use App\Services\Fiscal\Read\FiscalCoverage;
use App\Services\Fiscal\Read\FiscalDocuments;
use App\Services\Fiscal\Read\FiscalProcessing;
use App\Services\Fiscal\Support\FiscalEnvironment;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\BusinessTestCase;

class FiscalWorkflowRegressionTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('fiscal');
        Bus::fake();
        config(['fiscal.manifestacao_enabled' => true, 'fiscal.manifestacao_rollout_mode' => 'fleet']);
    }

    public function test_reconciliation_establishes_and_restores_cli_tenant(): void
    {
        [$account, , $summary] = $this->summary();
        resolve(CurrentTenant::class)->accountId = null;
        $this->artisan('fiscal:reconcile-xml-accounting', ['--account' => $account->id])->assertSuccessful();
        $this->assertNull(resolve(CurrentTenant::class)->accountId);
        $this->assertNull($summary->fresh()->processing_state);
    }

    public function test_aggregate_endpoints_count_only_available_complete_xml(): void
    {
        [$account, $client] = $this->summary();
        FiscalDocument::factory()->event('110111-1')->create(['client_id' => $client->id]);
        FiscalDocument::factory()->create(['client_id' => $client->id, 'storage_state' => 'missing']);
        FiscalDocument::factory()->withStoredXml()->create(['client_id' => $client->id]);
        $this->assertSame(1, resolve(FiscalCoverage::class)->summary($account->id)['documents']['total']);
        $this->assertSame(1, resolve(FiscalClients::class)->summary($account->id, [])[0]['total']);
    }

    public function test_repeated_summary_preserves_complete_note_outside_processing(): void
    {
        [$account, $client, $summary] = $this->summary();
        $full = FiscalDocument::factory()->withStoredXml()->create(['client_id' => $client->id, 'chave_acesso' => $summary->chave_acesso]);
        $coordinator = resolve(FiscalProcessingCoordinator::class);
        $coordinator->afterCompleteDocumentStored($full);
        $completedAt = $summary->fresh()->processing_completed_at->toIso8601String();
        $this->travel(1)->minutes();
        try {
            $coordinator->afterSummaryStored($summary->fresh(), $client, $account);
            $this->assertSame($completedAt, $summary->fresh()->processing_completed_at->toIso8601String());
        } finally {
            $this->travelBack();
        }
        $this->assertSame(FiscalProcessingState::Complete, $summary->fresh()->processing_state);
        $this->assertSame(['active' => 0, 'deferred' => 0, 'attention' => 0], resolve(FiscalProcessing::class)->summaryCounts($account->id));
    }

    public function test_accepted_science_reconciles_to_xml_recovery_after_deadline(): void
    {
        [$account, $client, $summary] = $this->summary();
        $summary->forceFill(['authorized_at' => now()->subDays(30)])->saveQuietly();
        $this->manifestation($summary)->forceFill(['outcome' => FiscalManifestationOutcome::Sent])->save();
        $this->artisan('fiscal:reconcile-xml-accounting', ['--account' => $account->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(FiscalProcessingState::AwaitingXml, $summary->fresh()->processing_state);
        Bus::assertDispatched(RecoverFiscalXmlJob::class);
        Bus::assertNotDispatched(SendFiscalManifestationJob::class);
    }

    public function test_backfill_excludes_an_artifact_with_a_wrong_persisted_length(): void
    {
        [$account, $client] = $this->summary();
        $document = FiscalDocument::factory()->withStoredXml(file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml')))->create(['client_id' => $client->id]);
        $document->forceFill(['xml_bytes' => 1])->saveQuietly();
        $this->artisan('fiscal:reconcile-xml-accounting', ['--account' => $account->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('corrupt', $document->fresh()->storage_state->value);
    }

    public function test_suspension_after_enqueue_withholds_science(): void
    {
        [$account, , $summary] = $this->summary();
        $manifestation = $this->manifestation($summary);
        $account->update(['status' => 'suspended']);
        $this->runScience($summary);
        $this->assertSame(FiscalProcessingState::Attention, $summary->fresh()->processing_state);
        $this->assertSame(FiscalProcessingReason::AccountSuspended, $summary->fresh()->processing_reason);
        $this->assertNull($manifestation->fresh()->sent_at);
        Http::assertNothingSent();
    }

    public function test_expired_authorization_enters_attention_instead_of_permanent_queue(): void
    {
        [, , $summary] = $this->summary();
        $summary->forceFill(['authorized_at' => now()->subDays(11), 'processing_state' => 'awaiting_science'])->saveQuietly();
        $this->manifestation($summary);
        $this->runScience($summary);
        $this->assertSame(FiscalProcessingState::Attention, $summary->fresh()->processing_state);
        $this->assertSame(FiscalProcessingReason::ScienceDeadlineExpired, $summary->fresh()->processing_reason);
    }

    public function test_cancelled_note_after_enqueue_withholds_science(): void
    {
        [, , $summary] = $this->summary();
        $this->manifestation($summary);
        $summary->forceFill(['provider_note_status' => 'cancelled'])->saveQuietly();
        $this->runScience($summary);
        $this->assertSame(FiscalProcessingReason::NoteCancelled, $summary->fresh()->processing_reason);
        Http::assertNothingSent();
    }

    public function test_conclusive_event_after_enqueue_withholds_science(): void
    {
        [, $client, $summary] = $this->summary();
        $this->manifestation($summary);
        FiscalDocument::factory()->event('210200-1')->create(['client_id' => $client->id, 'chave_acesso' => $summary->chave_acesso]);
        $this->runScience($summary);
        $this->assertSame(FiscalProcessingReason::ConclusiveEvent, $summary->fresh()->processing_reason);
        Http::assertNothingSent();
    }

    public function test_missing_certificate_stops_in_attention_without_exhausting_queue(): void
    {
        [, $client, $summary] = $this->summary();
        $client->currentCertificate->delete();
        $this->manifestation($summary);
        $this->runScience($summary);
        $this->assertSame(FiscalProcessingReason::CertificateMissing, $summary->fresh()->processing_reason);
        $this->assertSame(FiscalProcessingState::Attention, $summary->fresh()->processing_state);
    }

    public function test_interrupted_science_queue_is_recoverable_from_due_work(): void
    {
        [, , $summary] = $this->summary();
        $summary->forceFill(['processing_state' => 'science_queued', 'next_attempt_at' => now()->subMinute()])->saveQuietly();
        $this->assertSame(1, resolve(FiscalProcessingDueWork::class)->dispatchDue());
        Bus::assertDispatched(SendFiscalManifestationJob::class);
    }

    public function test_expired_worker_cannot_release_a_new_workers_claim(): void
    {
        [, , $summary] = $this->summary();
        $coordinator = resolve(FiscalProcessingCoordinator::class);
        $this->assertTrue($coordinator->claim($summary, 120));
        $this->travel(121)->seconds();
        try {
            $replacement = $summary->fresh();
            $this->assertTrue($coordinator->claim($replacement, 120));
            $coordinator->releaseClaim($summary);
            $this->assertNotNull($replacement->fresh()->processing_claim_expires_at);
        } finally {
            $this->travelBack();
        }
    }

    public function test_event_acceptance_requires_complete_correlation_identity(): void
    {
        $this->expectException(FiscalException::class);
        resolve(ManifestationEventResponseCorrelator::class)->assertCorrelates(str_repeat('7', 44), FiscalManifestationEventType::CienciaEmissao, 1, [
            'chave_acesso' => null, 'tp_evento' => null, 'event_seq' => null, 'tp_amb' => FiscalEnvironment::tpAmb(),
        ]);
    }

    public function test_document_501_with_missing_bytes_never_appears_as_ready(): void
    {
        [$account, $client] = $this->summary();
        FiscalDocument::factory()->count(500)->withStoredXml()->create(['account_id' => $account->id, 'client_id' => $client->id, 'emissao_at' => now()->subDay()]);
        $missing = FiscalDocument::factory()->create(['client_id' => $client->id, 'storage_state' => 'available', 'emissao_at' => now()]);
        $page = resolve(FiscalDocuments::class)->page($account->id, []);
        $this->assertSame(500, $page['rows']->total());
        $this->assertNotContains($missing->id, $page['rows']->getCollection()->modelKeys());
        $this->assertSame('missing', $missing->fresh()->storage_state->value);
        $this->assertSame(500, resolve(FiscalCoverage::class)->summary($account->id)['documents']['total']);
    }

    /** @return array{Account, Client, FiscalDocument} */
    private function summary(): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $client = Client::factory()->company()->create(['account_id' => $account->id, 'tax_id' => '00000000000191']);
        ClientCertificate::factory()->create(['client_id' => $client->id]);
        $summary = FiscalDocument::factory()->summary()->create([
            'client_id' => $client->id, 'authorized_at' => now()->subDay(),
            'delivery_origin' => 'authenticated_distribution', 'delivery_environment' => FiscalEnvironment::selecionar(),
            'queried_tax_id' => $client->tax_id, 'delivery_received_at' => now(), 'delivery_correlation_id' => fake()->uuid(),
            'emitente_cnpj' => '99999999999999',
        ]);

        return [$account, $client, $summary];
    }

    private function manifestation(FiscalDocument $summary): FiscalManifestation
    {
        return resolve(FiscalManifestationStore::class)->registrarPedido($summary->account_id, $summary->client_id, $summary->chave_acesso, FiscalManifestationEventType::CienciaEmissao, 1, 'regression');
    }

    private function runScience(FiscalDocument $summary): void
    {
        (new SendFiscalManifestationJob($summary->account_id, $summary->client_id, $summary->chave_acesso, FiscalManifestationEventType::CienciaEmissao))
            ->handle(resolve(FiscalManifestationStore::class), resolve(RecepcaoEventoConnector::class));
    }
}
