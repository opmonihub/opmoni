<?php

namespace Tests\Feature;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Read\FiscalDocumentReadiness;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ErpAccountingPublicationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('fiscal');
    }

    public function test_delivery_publishes_original_verified_bytes_to_private_fiscal_storage(): void
    {
        [$document, $client] = $this->scenario();
        $handoff = resolve(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $office = FiscalDocument::withoutGlobalScope('account')->findOrFail($result->fiscal_document_id);
        $this->assertTrue(resolve(FiscalDocumentReadiness::class)->isReadyRow($office));
        $this->assertSame('<nfeProc/>', Storage::disk('fiscal')->get($office->storage_path));
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

    private function scenario(): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $client = Client::factory()->company()->create(['account_id' => $account->id, 'tax_id' => $company->cnpj]);
        ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $account->id]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        Storage::disk('local')->put('erp/fiscal/publication.xml', '<nfeProc/>');
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id,
            'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 9001,
            'access_key' => '35251030288513000100550010000090011234567890', 'status' => 'authorized',
            'idempotency_key' => fake()->uuid(), 'storage_path' => 'erp/fiscal/publication.xml',
            'artifact_byte_length' => 10, 'artifact_sha256' => hash('sha256', '<nfeProc/>'), 'authorized_at' => now(),
        ]);

        return [$document, $client];
    }
}
