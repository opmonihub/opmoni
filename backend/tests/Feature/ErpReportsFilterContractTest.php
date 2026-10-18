<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ErpReportsFilterContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ErpCompany $company;

    private ErpEstablishment $establishment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
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
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $this->user = User::factory()->create();
        $this->company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $this->company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-Company-Id', (string) $this->company->id);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
    }

    public function test_invalid_establishment_zero_is_not_silently_treated_as_all_establishments(): void
    {
        $this->getJson('/api/v1/erp/reports/purchases?erp_establishment_id=0')->assertUnprocessable();
        $this->postJson('/api/v1/erp/reports/purchases/exports', ['erp_establishment_id' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('erp_report_exports', 0);
    }

    public function test_foreign_establishment_and_invalid_date_range_are_rejected_for_reads_and_exports(): void
    {
        $foreign = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $foreign->id]);
        foreach ([['erp_establishment_id' => $establishment->id], ['date_from' => '2026-10-31', 'date_to' => '2026-10-01'], ['date_from' => 'invalid']] as $filters) {
            $this->getJson('/api/v1/erp/reports/purchases?'.http_build_query($filters))->assertUnprocessable();
            $this->postJson('/api/v1/erp/reports/purchases/exports', $filters)->assertUnprocessable();
        }
        $this->assertDatabaseCount('erp_report_exports', 0);
    }

    public function test_period_and_establishment_reconcile_exact_posted_total_and_export_snapshot(): void
    {
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        foreach ([[$this->establishment->id, '2026-10-10', '12.3400', 'posted'], [$second->id, '2026-10-10', '900.0000', 'posted'], [$this->establishment->id, '2026-09-30', '800.0000', 'posted'], [$this->establishment->id, '2026-10-10', '700.0000', 'draft']] as $i => [$establishment, $date, $total, $status]) {
            ErpPurchase::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment, 'document_no' => 'filter-'.$i, 'status' => $status, 'total_amount' => $total, 'currency' => 'BRL', 'posted_at' => $date.' 12:00:00']);
        }
        $filters = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'erp_establishment_id' => $this->establishment->id];
        $report = $this->getJson('/api/v1/erp/reports/purchases?'.http_build_query($filters))->assertOk()->assertJsonPath('data.totals.document_count', 1)->assertJsonPath('data.totals.posted_total', '12.3400');
        $export = $this->postJson('/api/v1/erp/reports/purchases/exports', $filters)->assertCreated();
        $this->getJson('/api/v1/erp/reports/exports/'.$export->json('data.id'))->assertOk()->assertJsonPath('data.report', $report->json('data'));
    }

    public function test_explicit_currency_never_combines_foreign_posted_amounts(): void
    {
        foreach (['BRL' => '12.3400', 'USD' => '99.9900'] as $currency => $amount) {
            ErpPurchase::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'document_no' => $currency, 'status' => 'posted', 'total_amount' => $amount, 'currency' => $currency, 'posted_at' => now()]);
        }
        $this->getJson('/api/v1/erp/reports/purchases')->assertOk()->assertJsonPath('data.totals.posted_total', '12.3400')->assertJsonPath('data.filters.currency', 'BRL');
        $this->getJson('/api/v1/erp/reports/purchases?currency=USD&status=posted')->assertOk()->assertJsonPath('data.totals.posted_total', '99.9900')->assertJsonPath('data.filters.currency', 'USD');
        foreach (['currency=usd', 'currency=INVALID', 'status=draft', 'direction=receivable'] as $invalid) {
            $this->getJson('/api/v1/erp/reports/purchases?'.$invalid)->assertUnprocessable();
        }
    }

    public function test_financial_filters_use_persisted_status_direction_and_currency_without_mislabeling_payments(): void
    {
        foreach ([['receivable', 'open', 'BRL', '11.1200'], ['payable', 'open', 'BRL', '88.0000'], ['receivable', 'settled', 'BRL', '55.0000'], ['receivable', 'open', 'USD', '99.0000']] as [$direction, $status, $currency, $amount]) {
            ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'direction' => $direction, 'status' => $status, 'currency' => $currency, 'amount' => $amount, 'open_amount' => $amount, 'category' => 'manual']);
        }
        $this->getJson('/api/v1/erp/reports/financial?status=open&direction=receivable&currency=BRL')->assertOk()->assertJsonPath('data.totals.obligations_amount', '11.1200')->assertJsonMissingPath('data.totals.payments_posted_total')->assertJsonPath('data.filters.direction', 'receivable');
        $this->getJson('/api/v1/erp/reports/financial?status=settled')->assertOk()->assertJsonPath('data.totals.obligations_amount', '55.0000');
        $this->getJson('/api/v1/erp/reports/financial?status=paid')->assertUnprocessable();
    }

    public function test_fiscal_outcomes_remain_separate_and_status_filter_is_authoritative(): void
    {
        foreach (['authorized', 'draft', 'rejected', 'indeterminate', 'contingency_local'] as $number => $status) {
            ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => $number + 1, 'status' => $status, 'idempotency_key' => 'reports-'.$status]);
        }
        $this->getJson('/api/v1/erp/reports/fiscal')->assertOk()->assertJsonPath('data.totals.authorized_count', 1)->assertJsonPath('data.totals.indeterminate_count', 1)->assertJsonPath('data.totals.contingency_local_count', 1);
        $this->getJson('/api/v1/erp/reports/fiscal?status=rejected')->assertOk()->assertJsonPath('data.totals.authorized_count', 0)->assertJsonPath('data.totals.rejected_count', 1)->assertJsonPath('data.filters.status', 'rejected');
    }

    public function test_viewer_can_read_but_cannot_create_exports_under_current_server_policy(): void
    {
        ErpCompanyMembership::query()->where('user_id', $this->user->id)->update(['role' => 'viewer']);
        $this->getJson('/api/v1/erp/reports/purchases')->assertOk();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->assertDatabaseCount('erp_report_exports', 0);
    }
}
