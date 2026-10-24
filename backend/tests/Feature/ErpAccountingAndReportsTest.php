<?php

namespace Tests\Feature;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Reports\ErpReportExport;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\Support\BuildsVerifiedErpFiscalEvidence;
use Tests\TestCase;

class ErpAccountingAndReportsTest extends TestCase
{
    use ApprovesErpAccountingMappings;
    use BuildsVerifiedErpFiscalEvidence;
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'erp.release_mode' => 'production',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
    }

    /** @return array{user: User, company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>} */
    private function ownerContext(): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $this->login($user);

        return [
            'user' => $user,
            'company' => $company,
            'establishment' => $establishment,
            'headers' => ['X-Company-Id' => (string) $company->id],
        ];
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function login(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    private function officeClient(Account $office, string $cnpj = self::VALID_CNPJ): Client
    {
        return resolve(CurrentTenant::class)->run($office->id, fn (): Client => Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => $cnpj,
        ]));
    }

    private function authorizedFiscalDocument(ErpCompany $company, ErpEstablishment $establishment): ErpFiscalDocument
    {
        Storage::fake('local');
        $path = 'erp/fiscal/test-doc.xml';
        Storage::disk('local')->put($path, '<nfeProc/>');

        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 9001,
            'access_key' => '35251030288513000100550010000090011234567890',
            'status' => 'authorized',
            'idempotency_key' => 'handoff-doc-'.uniqid(),
            'storage_path' => $path,
            'artifact_byte_length' => Storage::disk('local')->size($path),
            'artifact_sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'commercial_snapshot' => ['total_amount' => '150.00'],
            'authorized_at' => now(),
        ]);
        $this->attachVerifiedFiscalEvidence($document);

        return $document;
    }

    public function test_accounting_handoff_delivers_to_mapped_office_client(): void
    {
        $ctx = $this->ownerContext();
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $ctx['user']->id,
        ]);
        $client = $this->officeClient($office);
        $this->approveAccountingMapping($delegation, $ctx['establishment'], $client);

        $document = $this->authorizedFiscalDocument($ctx['company'], $ctx['establishment']);
        $handoff = app(ErpAccountingHandoffService::class);
        $delivery = $handoff->ensureDeliveryIntent($document);
        $this->assertNotNull($delivery);
        $result = $handoff->deliver($delivery);

        $this->assertSame(ErpAccountingDelivery::STATUS_DELIVERED, $result->status);
        $this->assertSame($client->id, $result->client_id);
        $this->assertNotNull($result->fiscal_document_id);
        $officeDocument = FiscalDocument::withoutGlobalScope('account')->findOrFail($result->fiscal_document_id);
        $this->assertSame($office->id, $officeDocument->account_id);
        $this->assertSame($document->access_key, $officeDocument->chave_acesso);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'delivered')
            ->assertJsonPath('data.0.fiscal_document_id', $result->fiscal_document_id);
    }

    public function test_accounting_retry_recovers_after_storage_becomes_available(): void
    {
        $ctx = $this->ownerContext();
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $ctx['user']->id,
        ]);
        $client = $this->officeClient($office);
        $this->approveAccountingMapping($delegation, $ctx['establishment'], $client);

        Storage::fake('local');
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_establishment_id' => $ctx['establishment']->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 9002,
            'access_key' => '35251030288513000100550010000090021234567890',
            'status' => 'authorized',
            'idempotency_key' => 'handoff-doc-retry',
            'storage_path' => 'erp/fiscal/missing.xml',
            'artifact_byte_length' => 10,
            'artifact_sha256' => hash('sha256', '<nfeProc/>'),
            'authorized_at' => now(),
        ]);

        $originalXml = $this->attachVerifiedFiscalEvidence($document);
        Storage::disk('local')->delete($document->storage_path);
        $handoff = app(ErpAccountingHandoffService::class);
        $delivery = $handoff->ensureDeliveryIntent($document);
        $failed = $handoff->deliver($delivery);
        $this->assertSame(ErpAccountingDelivery::STATUS_FAILED, $failed->status);
        $this->assertSame('storage_unavailable', $failed->reason_code);

        Storage::disk('local')->put($document->storage_path, $originalXml);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'accounting-retry-storage-1'])
            ->postJson('/api/v1/erp/accounting-deliveries/'.$failed->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reason_code', 'accounting_worker_not_configured');
        $this->assertSame('delivered', $handoff->deliver($failed->fresh())->status);
    }

    public function test_foreign_office_client_is_not_used_for_handoff(): void
    {
        $ctx = $this->ownerContext();
        $office = Account::factory()->create();
        $foreignOffice = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $ctx['user']->id,
        ]);
        $this->officeClient($foreignOffice);

        $document = $this->authorizedFiscalDocument($ctx['company'], $ctx['establishment']);
        $handoff = app(ErpAccountingHandoffService::class);
        $delivery = $handoff->ensureDeliveryIntent($document);
        $result = $handoff->deliver($delivery);

        $this->assertSame(ErpAccountingDelivery::STATUS_BLOCKED, $result->status);
        $this->assertSame('client_mapping_missing', $result->reason_code);
        $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_sales_report_reconciles_posted_ledger_rows(): void
    {
        $ctx = $this->ownerContext();
        ErpSale::query()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_establishment_id' => $ctx['establishment']->id,
            'invoice_no' => 'V-100',
            'status' => 'posted',
            'subtotal_amount' => '100.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '100.0000',
            'currency' => 'BRL',
            'posted_at' => now(),
        ]);
        ErpSale::query()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_establishment_id' => $ctx['establishment']->id,
            'invoice_no' => 'V-draft',
            'status' => 'draft',
            'subtotal_amount' => '999.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '999.0000',
            'currency' => 'BRL',
        ]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/reports/sales')
            ->assertOk()
            ->assertJsonPath('data.totals.document_count', 1)
            ->assertJsonPath('data.totals.posted_total', '100.0000');

        ErpPurchase::query()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_establishment_id' => $ctx['establishment']->id,
            'document_no' => 'C-1',
            'status' => 'posted',
            'total_amount' => '55.5000',
            'currency' => 'BRL',
            'posted_at' => now(),
        ]);

        $exportResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/reports/purchases/exports', [])
            ->assertCreated();

        $exportId = $exportResponse->json('data.id');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/reports/exports/'.$exportId)
            ->assertOk()
            ->assertJsonPath('data.report.totals.posted_total', '55.5000');

        $otherCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignExport = ErpReportExport::query()->create([
            'erp_company_id' => $otherCompany->id,
            'user_id' => $ctx['user']->id,
            'report_type' => 'sales',
            'filters' => [],
            'status' => 'completed',
            'storage_path' => 'erp/report-exports/foreign.json',
        ]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/reports/exports/'.$foreignExport->id)
            ->assertNotFound();
    }

    public function test_release_gate_blocks_accounting_and_reports_when_disabled(): void
    {
        config(['erp.release_enabled' => false]);
        $ctx = $this->ownerContext();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries')
            ->assertForbidden()
            ->assertJsonPath('code', 'erp_release_disabled');

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/reports/sales')
            ->assertForbidden()
            ->assertJsonPath('code', 'erp_release_disabled');
    }

    public function test_delegated_reader_cannot_retry_handoff(): void
    {
        $ctx = $this->ownerContext();
        $office = Account::factory()->create();
        $delegateUser = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $office->id,
            'user_id' => $delegateUser->id,
            'role' => 'admin',
        ]);
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $ctx['user']->id,
            'permission_allowlist' => ['erp.read'],
        ]);
        $this->officeClient($office);

        $document = $this->authorizedFiscalDocument($ctx['company'], $ctx['establishment']);
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($document);
        $this->assertNotNull($delivery);

        $this->login($delegateUser);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $ctx['company']->id])
            ->assertOk();

        $this->withHeaders(['X-Company-Id' => (string) $ctx['company']->id, 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_stock_overview_uses_posted_inventory_balances(): void
    {
        $ctx = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_unit_id' => $unit->id,
        ]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);
        ErpStockBalance::query()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_establishment_id' => $ctx['establishment']->id,
            'erp_product_variant_id' => $variant->id,
            'quantity_on_hand' => '4.0000',
            'average_cost' => '10.0000',
        ]);
        ErpPurchase::query()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_establishment_id' => $ctx['establishment']->id,
            'document_no' => 'C-overview',
            'status' => 'posted',
            'total_amount' => '40.0000',
            'currency' => 'BRL',
            'posted_at' => now(),
        ]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.sections.stock.quantity_on_hand', '4.0000')
            ->assertJsonPath('data.sections.purchases.posted_total', '40.0000');
    }
}
