<?php

namespace Tests\Feature;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Read\FiscalDocumentReadiness;
use App\Services\Fiscal\Support\FiscalTrustedDeliveryContext;
use App\Support\ErpCompanyContext;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\Support\BuildsVerifiedErpFiscalEvidence;
use Tests\TestCase;

class ErpAccountingPublicationRegressionTest extends TestCase
{
    use ApprovesErpAccountingMappings;
    use BuildsVerifiedErpFiscalEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('fiscal');
    }

    public function test_unproved_legacy_authorized_stub_cannot_be_published_to_accounting(): void
    {
        [$document] = $this->scenario(false);
        $result = app(ErpAccountingHandoffService::class)->deliver(app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($document));
        $this->assertSame('blocked', $result->status);
        $this->assertSame('fiscal_authorization_unverified', $result->reason_code);
        $this->assertDatabaseCount('fiscal_documents', 0);
        $this->assertSame('authorized', $document->fresh()->status);
    }

    public function test_unverified_source_can_be_repaired_then_retry_projection_without_reissuance(): void
    {
        [$document] = $this->scenario(false);
        $handoff = app(ErpAccountingHandoffService::class);
        $delivery = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $this->assertSame('fiscal_authorization_unverified', $delivery->reason_code);
        $this->attachVerifiedFiscalEvidence($document);
        $this->assertSame('pending', $handoff->retry($delivery)->status);
        $this->assertSame('delivered', $handoff->deliver($delivery->fresh())->status);
        $this->assertDatabaseCount('erp_accounting_deliveries', 1);
        $this->assertSame(1, $document->attempts()->where('action', 'document:transmit')->count());
    }

    public function test_delivery_publishes_original_verified_bytes_to_private_fiscal_storage(): void
    {
        [$document, $client] = $this->scenario();
        $handoff = resolve(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $office = FiscalDocument::withoutGlobalScope('account')->findOrFail($result->fiscal_document_id);
        $this->assertTrue(resolve(FiscalDocumentReadiness::class)->isReadyRow($office));
        $this->assertSame(Storage::disk('local')->get($document->storage_path), Storage::disk('fiscal')->get($office->storage_path));
        $this->assertSame('erp_issued', $office->delivery_origin->value);
        $this->assertSame($client->id, $office->client_id);
    }

    public function test_existing_summary_is_preserved_beside_the_published_complete_document(): void
    {
        [$document, $client] = $this->scenario();
        $summary = FiscalDocument::factory()->summary()->create(['client_id' => $client->id, 'chave_acesso' => $document->access_key]);
        $handoff = resolve(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $this->assertNotSame($summary->id, $result->fiscal_document_id);
        $this->assertSame('summary', $summary->fresh()->stage->value);
        $this->assertSame('complete', $summary->fresh()->processing_state->value);
    }

    public function test_corrupted_issued_artifact_is_not_reported_delivered(): void
    {
        [$document] = $this->scenario();
        Storage::disk('local')->put($document->storage_path, '<tampered/>');
        $handoff = resolve(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $this->assertSame('failed', $result->status);
        $this->assertSame('artifact_integrity_failed', $result->reason_code);
        $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_conflicting_capture_preserves_accepted_erp_artifact_and_surfaces_attention(): void
    {
        [$document, $client] = $this->scenario();
        $handoff = resolve(ErpAccountingHandoffService::class);
        $delivered = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $accepted = FiscalDocument::withoutGlobalScope('account')->findOrFail($delivered->fiscal_document_id);
        $hash = $accepted->sha256;
        $capture = new PulledDocument(
            model: FiscalModel::Nfe, kind: FiscalKind::Document, stage: FiscalStage::Document,
            chave: $document->access_key, eventId: '', emitenteCnpj: '11222333000181', destinatarioCnpj: null, valorTotal: null, digVal: null,
            nsu: 999, schema: 'procNFe_v4.00.xsd', emissaoAt: null, eventoOcorridoEmAt: null, xml: '<conflicting/>',
        );
        $result = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $capture);
        $this->assertSame(Storage::disk('local')->get($document->storage_path), Storage::disk('fiscal')->get($accepted->storage_path));
        $this->assertSame($accepted->id, $result->id);
        $this->assertSame($hash, $result->sha256);
        $this->assertSame('erp_issued', $result->delivery_origin->value);
        $this->assertSame('attention', $result->processing_state->value);
        $this->assertSame('artifact_conflict', $result->processing_reason->value);
        $this->assertSame('delivered', $delivered->fresh()->status);
    }

    public function test_equal_capture_repairs_missing_and_corrupt_bytes_without_changing_erp_provenance(): void
    {
        [$document, $client] = $this->scenario();
        $handoff = resolve(ErpAccountingHandoffService::class);
        $delivered = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $accepted = FiscalDocument::withoutGlobalScope('account')->findOrFail($delivered->fiscal_document_id);
        $capture = $this->captured($document->access_key, Storage::disk('local')->get($document->storage_path));
        foreach ([null, '<corrupt/>'] as $bytes) {
            if ($bytes === null) {
                Storage::disk('fiscal')->delete($accepted->storage_path);
            } else {
                Storage::disk('fiscal')->put($accepted->storage_path, $bytes);
            }
            $result = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $capture);
            $this->assertSame($accepted->id, $result->id);
            $this->assertSame(Storage::disk('local')->get($document->storage_path), Storage::disk('fiscal')->get($accepted->storage_path));
            $this->assertTrue(app(FiscalDocumentReadiness::class)->isReadyRow($result));
            $this->assertSame('erp_issued', $result->delivery_origin->value);
            $this->assertSame($accepted->sha256, $result->sha256);
            $this->assertSame($accepted->nsu, $result->nsu);
        }
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_handoff_preserves_conflicting_capture_that_arrived_first(): void
    {
        [$document, $client] = $this->scenario();
        $accepted = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $this->captured($document->access_key, '<other/>'));
        $hash = $accepted->sha256;
        $handoff = resolve(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $this->assertSame('blocked', $result->status);
        $this->assertSame('artifact_conflict', $result->reason_code);
        $this->assertSame('<other/>', Storage::disk('fiscal')->get($accepted->storage_path));
        $this->assertSame($hash, $accepted->fresh()->sha256);
        $this->assertSame('attention', $accepted->fresh()->processing_state->value);
        $this->assertSame('artifact_conflict', $accepted->fresh()->processing_reason->value);
    }

    public function test_equal_hash_with_conflicting_environment_or_issuer_is_attention(): void
    {
        [$document, $client] = $this->scenario();
        $handoff = resolve(ErpAccountingHandoffService::class);
        $delivered = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $accepted = FiscalDocument::withoutGlobalScope('account')->findOrFail($delivered->fiscal_document_id);
        $context = new FiscalTrustedDeliveryContext(
            $client->account_id, $client->id, FiscalSource::NfeDistribuicao,
            'producao', $client->tax_id, CarbonImmutable::now(), 'test-capture',
        );
        $result = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $this->captured($document->access_key, Storage::disk('local')->get($document->storage_path)), $context);
        $this->assertSame('artifact_conflict', $result->processing_reason->value);
        $this->assertSame('homologacao', $result->delivery_environment);
        $this->assertSame('erp_issued', $result->delivery_origin->value);
        $this->assertSame(Storage::disk('local')->get($document->storage_path), Storage::disk('fiscal')->get($accepted->storage_path));
    }

    public function test_publication_failure_restores_context_and_attempt_limit_is_inspectable(): void
    {
        [$document] = $this->scenario();
        $local = Storage::disk('local');
        $fiscal = \Mockery::mock(FilesystemAdapter::class);
        $fiscal->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($local);
        Storage::shouldReceive('disk')->with('fiscal')->andReturn($fiscal);
        resolve(CurrentTenant::class)->accountId = 123456;
        resolve(ErpCompanyContext::class)->companyId = 654321;
        $handoff = resolve(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureDeliveryIntent($document);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = $handoff->deliver($intent);
            $this->assertSame('failed', $result->status);
            $this->assertSame('publication_failed', $result->reason_code);
            $this->assertSame($attempt, $result->attempts);
            $this->assertSame(123456, resolve(CurrentTenant::class)->accountId);
            $this->assertSame(654321, resolve(ErpCompanyContext::class)->companyId);
        }
        $exhausted = $handoff->retry($intent);
        $this->assertSame('blocked', $exhausted->status);
        $this->assertSame('max_attempts_exceeded', $exhausted->reason_code);
        $this->assertSame(5, $exhausted->attempts);
        $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_later_capture_cannot_replace_a_capture_already_in_accounting_conflict(): void
    {
        [$document, $client] = $this->scenario();
        $writer = app(FiscalDocumentWriter::class);
        $accepted = $writer->store($client, FiscalSource::NfeDistribuicao, $this->captured($document->access_key, '<first/>'));
        $handoff = resolve(ErpAccountingHandoffService::class);
        $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $later = $writer->store($client, FiscalSource::NfeDistribuicao, $this->captured($document->access_key, '<later/>'));
        $this->assertSame('<first/>', Storage::disk('fiscal')->get($accepted->storage_path));
        $this->assertSame($accepted->sha256, $later->sha256);
        $this->assertSame('artifact_conflict', $later->processing_reason->value);
        Storage::disk('fiscal')->delete($accepted->storage_path);
        $equal = $writer->store($client, FiscalSource::NfeDistribuicao, $this->captured($document->access_key, '<first/>'));
        $this->assertSame('<first/>', Storage::disk('fiscal')->get($accepted->storage_path));
        $this->assertSame('artifact_conflict', $equal->processing_reason->value);
        $this->assertSame('attention', $equal->processing_state->value);
    }

    #[DataProvider('branchCaptureOrders')]
    public function test_real_branch_capture_first_and_handoff_first_keep_same_note_and_issuer(bool $captureFirst): void
    {
        [$document, $client] = $this->scenario(true, true);
        $this->assertNotSame($document->company->cnpj, $document->establishment->cnpj);
        $xml = Storage::disk('local')->get($document->storage_path);
        if ($captureFirst) {
            app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $this->captured($document->access_key, $xml));
        }
        $handoff = app(ErpAccountingHandoffService::class);
        $delivered = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $this->assertSame('delivered', $delivered->status);
        $note = FiscalDocument::withoutGlobalScope('account')->findOrFail($delivered->fiscal_document_id);
        if (! $captureFirst) {
            $this->assertSame('11222333000181', $note->emitente_cnpj);
        }
        $equal = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $this->captured($document->access_key, $xml));
        $this->assertSame($note->id, $equal->id);
        $this->assertNotSame('artifact_conflict', $equal->processing_reason?->value);
    }

    public static function branchCaptureOrders(): array
    {
        return [[true], [false]];
    }

    public function test_branch_issuer_and_post_issuance_rotation_use_frozen_identity(): void
    {
        [$document, $client] = $this->scenario();
        $issuer = substr($document->access_key, 6, 14);
        $document->company->update(['cnpj' => '11222333000181']);
        $document->establishment->update(['cnpj' => '11222333000181']);
        $handoff = app(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $this->assertSame('delivered', $result->status);
        $office = FiscalDocument::withoutGlobalScope('account')->findOrFail($result->fiscal_document_id);
        $this->assertSame($issuer, $office->emitente_cnpj);
        $captured = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao,
            $this->captured($document->access_key, Storage::disk('local')->get($document->storage_path)));
        $this->assertSame($office->id, $captured->id);
        $this->assertNotSame('artifact_conflict', $captured->processing_reason?->value);
    }

    private function captured(string $key, string $bytes): PulledDocument
    {
        return new PulledDocument(
            model: FiscalModel::Nfe, kind: FiscalKind::Document, stage: FiscalStage::Document,
            chave: $key, eventId: '', emitenteCnpj: null, destinatarioCnpj: null, valorTotal: null, digVal: null,
            nsu: 999, schema: 'procNFe_v4.00.xsd', emissaoAt: null, eventoOcorridoEmAt: null, xml: $bytes,
        );
    }

    private function scenario(bool $verified = true, bool $branch = false): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $client = Client::factory()->company()->create(['account_id' => $account->id, 'tax_id' => $company->cnpj]);
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $account->id]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $branch ? '11222333000181' : $company->cnpj]);
        $this->approveAccountingMapping($delegation, $establishment, $client);
        Storage::disk('local')->put('erp/fiscal/publication.xml', '<nfeProc/>');
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id,
            'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 9001,
            'access_key' => '35261000000000000191550010000000011123456780', 'status' => 'authorized',
            'idempotency_key' => fake()->uuid(), 'storage_path' => 'erp/fiscal/publication.xml',
            'artifact_byte_length' => 10, 'artifact_sha256' => hash('sha256', '<nfeProc/>'), 'authorized_at' => now(),
        ]);

        if ($verified) {
            $this->attachVerifiedFiscalEvidence($document);
        }

        return [$document, $client];
    }
}
