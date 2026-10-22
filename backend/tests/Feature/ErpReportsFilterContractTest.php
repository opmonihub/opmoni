<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpStockAllocation;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->freezeTime();
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
        $this->getJson('/api/v1/erp/reports/fiscal')->assertOk()->assertJsonPath('data.totals.authorized_count', 1)->assertJsonPath('data.totals.indeterminate_count', 1)->assertJsonPath('data.totals.contingency_local_count', 1)
            ->assertJsonPath('data.ledgers.documents.timestamp_field', 'created_at')
            ->assertJsonPath('data.ledgers.documents.basis', 'current_status_for_created_cohort')
            ->assertJsonPath('data.ledgers.documents.row_count', 5)
            ->assertJsonPath('data.ledgers.documents.totals.authorized_count', 1);
        $this->getJson('/api/v1/erp/reports/fiscal?status=rejected')->assertOk()->assertJsonPath('data.totals.authorized_count', 0)->assertJsonPath('data.totals.rejected_count', 1)->assertJsonPath('data.filters.status', 'rejected');
    }

    public function test_viewer_can_read_but_cannot_create_exports_under_current_server_policy(): void
    {
        ErpCompanyMembership::query()->where('user_id', $this->user->id)->update(['role' => 'viewer']);
        $this->getJson('/api/v1/erp/reports/purchases')->assertOk();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->assertDatabaseCount('erp_report_exports', 0);
    }

    public function test_current_aging_is_not_a_historical_period_balance_and_preserves_independent_return_credit(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(12, 0));
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'currency' => 'BRL', 'category' => 'sale', 'status' => 'open', 'posted_at' => '2026-09-10 12:00:00'];
        $original = ErpObligation::create($base + ['direction' => 'receivable', 'amount' => '100.0000', 'open_amount' => '40.0000', 'due_on' => '2026-10-10', 'source_type' => 'erp_sale', 'source_id' => 12]);
        $credit = ErpObligation::create(array_replace($base, ['direction' => 'payable', 'amount' => '25.0000', 'open_amount' => '25.0000', 'due_on' => '2026-10-25', 'source_type' => 'erp_sale_return', 'source_id' => 13, 'return_of_id' => $original->id, 'posted_at' => '2026-10-20 12:00:00']));
        ErpObligation::create($base + ['direction' => 'payable', 'amount' => '5.0000', 'open_amount' => '5.0000']);
        $report = $this->getJson('/api/v1/erp/reports/financial?date_from=2026-10-01&date_to=2026-10-21')->assertOk();
        $report->assertJsonPath('data.generated_at', '2026-10-22T12:00:00+00:00')
            ->assertJsonPath('data.ledgers.obligations.basis', 'current_position')
            ->assertJsonPath('data.ledgers.obligations.totals.gross_original_due', '45.0000')
            ->assertJsonPath('data.ledgers.obligations.totals.opposite_return_credit', '25.0000')
            ->assertJsonPath('data.ledgers.obligations.totals.net_exposure', '10.0000')
            ->assertJsonPath('data.ledgers.obligations.rows.0.days_overdue', 12)
            ->assertJsonPath('data.ledgers.obligations.rows.1.return_of_id', $original->id)
            ->assertJsonPath('data.ledgers.obligations.rows.1.id', $credit->id)
            ->assertJsonPath('data.ledgers.obligations.totals.overdue', '40.0000')
            ->assertJsonPath('data.ledgers.obligations.totals.not_yet_due', '25.0000')
            ->assertJsonPath('data.ledgers.obligations.totals.undated', '5.0000')
            ->assertJsonPath('data.ledgers.obligation_activity.row_count', 1)
            ->assertJsonPath('data.ledgers.obligation_activity.timestamp_field', 'posted_at')
            ->assertJsonMissingPath('data.ledgers.obligation_activity.rows.0.open_amount')
            ->assertJsonMissingPath('data.ledgers.obligation_activity.totals.open_amount')
            ->assertJsonPath('data.ledgers.obligation_activity.totals.amount', '25.0000')
            ->assertJsonPath('data.historical_position', 'unavailable');
        $this->assertSame('40.0000', $original->fresh()->open_amount);
    }

    public function test_posted_payments_signed_allocations_and_cash_reversals_are_distinct_period_ledgers(): void
    {
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'currency' => 'BRL', 'posted_at' => '2026-10-10 12:00:00'];
        $obligation = ErpObligation::create($base + ['direction' => 'receivable', 'status' => 'open', 'category' => 'manual', 'amount' => '100.0000', 'open_amount' => '70.0000']);
        $payment = ErpPayment::create($base + ['direction' => 'in', 'amount' => '50.0000', 'method' => 'cash', 'status' => 'posted']);
        ErpPayment::create($base + ['direction' => 'in', 'amount' => '-20.0000', 'method' => 'cash', 'status' => 'posted', 'reversal_of_id' => $payment->id]);
        $allocation = ErpPaymentAllocation::create(['erp_payment_id' => $payment->id, 'erp_obligation_id' => $obligation->id, 'amount' => '50.0000', 'posted_at' => $base['posted_at']]);
        ErpPaymentAllocation::create(['erp_payment_id' => $payment->id, 'erp_obligation_id' => $obligation->id, 'amount' => '-20.0000', 'posted_at' => $base['posted_at'], 'reversal_of_id' => $allocation->id]);
        $register = ErpCashRegister::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'name' => 'Reports']);
        $session = ErpCashSession::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_cash_register_id' => $register->id, 'currency' => 'BRL', 'operator_user_id' => $this->user->id, 'opened_by_user_id' => $this->user->id, 'opened_at' => now()]);
        $cash = ErpCashMovement::factory()->forSession($session)->create($base + ['direction' => 'in', 'kind' => 'payment', 'amount' => '50.0000', 'erp_payment_id' => $payment->id]);
        ErpCashMovement::factory()->forSession($session)->create($base + ['direction' => 'out', 'kind' => 'reversal', 'amount' => '20.0000', 'reversal_of_id' => $cash->id]);
        $this->getJson('/api/v1/erp/reports/financial?date_from=2026-10-01&date_to=2026-10-31&status=open&direction=receivable')
            ->assertOk()->assertJsonPath('data.ledgers.payments.totals.amount', '30.0000')
            ->assertJsonPath('data.ledgers.allocations.totals.amount', '30.0000')
            ->assertJsonPath('data.ledgers.cash.totals.net_amount', '30.0000')
            ->assertJsonPath('data.ledgers.cash.rows.1.reversal_of_id', $cash->id)
            ->assertJsonPath('data.ledgers.payments.status_filter', 'posted')
            ->assertJsonPath('data.ledgers.cash.status_filter', null);
    }

    public function test_paid_sale_and_partial_return_keep_original_activity_and_current_credit_separate(): void
    {
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'currency' => 'BRL', 'posted_at' => '2026-10-10 12:00:00', 'status' => 'posted'];
        $sale = ErpSale::create($base + ['invoice_no' => 'paid', 'total_amount' => '100.0000']);
        $return = ErpSaleReturn::create($base + ['erp_sale_id' => $sale->id, 'total_amount' => '25.0000']);
        $due = ErpObligation::create(array_replace($base, ['status' => 'settled', 'direction' => 'receivable', 'category' => 'sale', 'amount' => '100.0000', 'open_amount' => '0.0000', 'source_type' => 'erp_sale', 'source_id' => $sale->id]));
        ErpObligation::create(array_replace($base, ['status' => 'open', 'direction' => 'payable', 'category' => 'return', 'amount' => '25.0000', 'open_amount' => '25.0000', 'source_type' => 'erp_sale_return', 'source_id' => $return->id, 'return_of_id' => $due->id]));
        $this->getJson('/api/v1/erp/reports/sales?date_from=2026-10-01&date_to=2026-10-31&status=posted')->assertOk()
            ->assertJsonPath('data.totals.posted_total', '100.0000')
            ->assertJsonPath('data.ledgers.returns.totals.amount', '25.0000')
            ->assertJsonPath('data.ledgers.returns.rows.0.source_id', $sale->id)
            ->assertJsonPath('data.ledgers.obligations.totals.gross_original_due', '0.0000')
            ->assertJsonPath('data.ledgers.obligations.totals.opposite_return_credit', '25.0000')
            ->assertJsonPath('data.ledgers.obligations.status_filter', null)
            ->assertJsonPath('data.ledgers.obligations.totals.net_exposure', '-25.0000');
    }

    public function test_stock_uses_remaining_lot_residual_cost_per_currency_and_unknown_quantity_not_average_cost(): void
    {
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id];
        $lot = ErpStockLot::factory()->create($base + ['quantity' => '3.0000', 'available_quantity' => '2.0000', 'consumed_quantity' => '1.0000', 'total_cost' => '10.0000', 'consumed_cost' => '3.3333']);
        ErpStockLot::factory()->create($base + ['currency' => 'USD', 'total_cost' => '9.0000']);
        ErpStockLot::factory()->create($base + ['currency' => null, 'total_cost' => null, 'cost_status' => 'unvalued', 'quantity' => '4.0000', 'available_quantity' => '4.0000']);
        ErpStockMovement::create($base + ['erp_product_variant_id' => $lot->erp_product_variant_id, 'direction' => 'in', 'quantity' => '3.0000', 'currency' => 'BRL', 'total_cost' => '10.0000', 'known_cost' => '10.0000', 'unvalued_quantity' => '0.0000', 'cost_status' => 'known', 'source_type' => 'manual', 'source_id' => 1, 'source_operation_key' => 'reports-stock', 'posted_at' => '2026-10-10 12:00:00']);
        $this->getJson('/api/v1/erp/reports/stock?date_from=2026-10-01&date_to=2026-10-31')->assertOk()
            ->assertJsonPath('data.ledgers.lots.basis', 'current_position')
            ->assertJsonPath('data.ledgers.lots.rows.0.remaining_cost', '6.6667')
            ->assertJsonPath('data.ledgers.lots.totals.known_cost_by_currency.BRL', '6.6667')
            ->assertJsonPath('data.ledgers.lots.totals.known_cost_by_currency.USD', '9.0000')
            ->assertJsonPath('data.ledgers.lots.totals.unvalued_quantity', '4.0000')
            ->assertJsonPath('data.totals.inventory_value', null)
            ->assertJsonPath('data.ledgers.movements.totals.quantity_in', '3.0000')
            ->assertJsonPath('data.historical_position', 'unavailable');
    }

    public function test_detail_is_bounded_but_exact_totals_include_all_rows_and_exclude_private_snapshots(): void
    {
        for ($i = 0; $i < 201; $i++) {
            ErpPurchase::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id,
                'document_no' => 'bounded-'.$i, 'status' => 'posted', 'total_amount' => '0.0001', 'currency' => 'BRL', 'posted_at' => now(),
                'source_operation_key' => 'private-operation-'.$i, 'supplier_snapshot' => ['secret' => 'private-party']]);
        }
        $report = $this->getJson('/api/v1/erp/reports/purchases')->assertOk()
            ->assertJsonPath('data.ledgers.documents.row_count', 201)->assertJsonPath('data.ledgers.documents.detail_truncated', true)
            ->assertJsonCount(200, 'data.ledgers.documents.rows')->assertJsonPath('data.ledgers.documents.totals.amount', '0.0201');
        $this->assertStringNotContainsString('private-operation', $report->getContent());
        $this->assertStringNotContainsString('private-party', $report->getContent());
        $overview = $this->getJson('/api/v1/erp/reports/overview')->assertOk()
            ->assertJsonPath('data.ledgers.sales.documents.basis', 'posted_activity')
            ->assertJsonPath('data.ledgers.stock.balances.basis', 'current_position');
    }

    public function test_allocation_activity_does_not_require_payment_to_have_been_posted_in_same_period(): void
    {
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'currency' => 'BRL', 'posted_at' => '2026-09-10 12:00:00'];
        $obligation = ErpObligation::create($base + ['direction' => 'receivable', 'status' => 'open', 'category' => 'manual', 'amount' => '100.0000', 'open_amount' => '70.0000']);
        $payment = ErpPayment::create($base + ['direction' => 'in', 'amount' => '30.0000', 'method' => 'manual', 'status' => 'posted']);
        ErpPaymentAllocation::create(['erp_payment_id' => $payment->id, 'erp_obligation_id' => $obligation->id, 'amount' => '30.0000', 'posted_at' => '2026-10-10 12:00:00']);
        $this->getJson('/api/v1/erp/reports/financial?date_from=2026-10-01&date_to=2026-10-31')->assertOk()
            ->assertJsonPath('data.ledgers.allocations.totals.amount', '30.0000')->assertJsonPath('data.ledgers.payments.row_count', 0);
    }

    public function test_stock_return_allocations_are_linked_compensations_not_new_outstanding_issues(): void
    {
        $lot = ErpStockLot::factory()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id]);
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $lot->erp_product_variant_id,
            'erp_stock_lot_id' => $lot->id, 'issued_at' => now(), 'currency' => 'BRL', 'cost_status' => 'known'];
        $original = ErpStockAllocation::create($base + ['quantity' => '10.0000', 'returned_quantity' => '3.0000', 'cost' => '10.0000', 'returned_cost' => '3.0000', 'ordinal' => 1, 'kind' => 'issue']);
        ErpStockAllocation::create($base + ['quantity' => '3.0000', 'cost' => '3.0000', 'ordinal' => 2, 'kind' => 'return', 'original_allocation_id' => $original->id]);
        $this->getJson('/api/v1/erp/reports/stock')->assertOk()
            ->assertJsonPath('data.ledgers.allocations.totals.quantity', '7.0000')
            ->assertJsonPath('data.ledgers.allocations.totals.return_compensation_quantity', '3.0000')
            ->assertJsonPath('data.ledgers.allocations.rows.1.original_allocation_id', $original->id)
            ->assertJsonPath('data.ledgers.allocations.rows.1.remaining_quantity', null);
    }

    public function test_legacy_payment_without_direction_cannot_fabricate_a_signed_net(): void
    {
        ErpPayment::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'currency' => 'BRL', 'posted_at' => now(),
            'amount' => '50.0000', 'method' => 'manual', 'status' => 'posted', 'direction' => null]);
        $this->getJson('/api/v1/erp/reports/financial')->assertOk()
            ->assertJsonPath('data.ledgers.payments.totals.amount', '50.0000')
            ->assertJsonPath('data.ledgers.payments.totals.unclassified_amount', '50.0000')
            ->assertJsonPath('data.ledgers.payments.totals.net_amount', null)
            ->assertJsonPath('data.ledgers.payments.totals.known_direction_net_amount', '0.0000');
    }

    public static function financialReportCategories(): array
    {
        return ['financial' => ['financial'], 'overview' => ['overview']];
    }

    #[DataProvider('financialReportCategories')]
    public function test_linked_scope_hides_whole_payment_allocations_and_cash_from_reports_and_new_snapshots(string $category): void
    {
        $fixture = $this->crossEstablishmentPayment();
        $prefix = $category === 'overview' ? 'data.ledgers.financial' : 'data.ledgers';
        $totals = $category === 'overview' ? 'data.sections.financial' : 'data.totals';
        $this->getJson('/api/v1/erp/reports/'.$category.'?'.http_build_query($fixture['filters']))
            ->assertOk()->assertJsonPath($totals.'.payments_posted_total', '107.0000')
            ->assertJsonPath($prefix.'.allocations.totals.amount', '47.0000')
            ->assertJsonPath($prefix.'.cash.totals.net_amount', '107.0000');

        $fixture['membership']->establishments()->detach($fixture['hidden_establishment_id']);
        $this->getJson('/api/v1/erp/payments/'.$fixture['payment']->id)->assertNotFound();
        $this->getJson('/api/v1/erp/payments')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.totals.0.amount', '7.0000');
        $report = $this->getJson('/api/v1/erp/reports/'.$category.'?'.http_build_query($fixture['filters']))->assertOk();
        $this->assertVisiblePaymentLedgers($report->json('data'), $category, $fixture);
        $export = $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertCreated();
        $snapshot = $this->getJson('/api/v1/erp/reports/exports/'.$export->json('data.id'))->assertOk();
        $this->assertVisiblePaymentLedgers($snapshot->json('data.report'), $category, $fixture);
    }

    public function test_linked_scope_checks_cash_and_allocations_even_when_the_payment_is_outside_the_activity_period(): void
    {
        $fixture = $this->crossEstablishmentPayment();
        $fixture['payment']->update(['posted_at' => now()->subMonth()]);
        $this->getJson('/api/v1/erp/reports/financial?'.http_build_query($fixture['filters']))->assertOk()
            ->assertJsonPath('data.totals.payments_posted_total', '7.0000')
            ->assertJsonPath('data.ledgers.allocations.totals.amount', '47.0000')
            ->assertJsonPath('data.ledgers.cash.totals.net_amount', '107.0000');
        $export = $this->postJson('/api/v1/erp/reports/financial/exports', $fixture['filters'])->assertCreated();
        $fixture['membership']->establishments()->detach($fixture['hidden_establishment_id']);
        $report = $this->getJson('/api/v1/erp/reports/financial?'.http_build_query($fixture['filters']))->assertOk();
        $this->assertVisiblePaymentLedgers($report->json('data'), 'financial', $fixture);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->json('data.id'))->assertConflict()
            ->assertJsonPath('code', 'export_requires_regeneration');
    }

    protected function crossEstablishmentPayment(): array
    {
        $this->freezeTime();
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'currency' => 'BRL', 'posted_at' => now()];
        $register = ErpCashRegister::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'name' => 'Linked scope']);
        $session = ErpCashSession::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_cash_register_id' => $register->id,
            'currency' => 'BRL', 'operator_user_id' => $this->user->id, 'opened_by_user_id' => $this->user->id, 'opened_at' => now()]);
        $payment = ErpPayment::create($base + ['direction' => 'in', 'amount' => '100.0000', 'method' => 'cash', 'status' => 'posted', 'erp_cash_session_id' => $session->id]);
        $visible = ErpPayment::create($base + ['direction' => 'in', 'amount' => '7.0000', 'method' => 'cash', 'status' => 'posted', 'erp_cash_session_id' => $session->id]);
        $obligation = ErpObligation::create($base + ['direction' => 'receivable', 'status' => 'open', 'category' => 'manual', 'amount' => '100.0000', 'open_amount' => '53.0000']);
        $hidden = ErpObligation::create(array_replace($base, ['erp_establishment_id' => $second->id, 'direction' => 'receivable', 'status' => 'open', 'category' => 'manual', 'amount' => '100.0000', 'open_amount' => '40.0000']));
        ErpPaymentAllocation::create(['erp_payment_id' => $payment->id, 'erp_obligation_id' => $obligation->id, 'amount' => '40.0000', 'posted_at' => now()]);
        ErpPaymentAllocation::create(['erp_payment_id' => $payment->id, 'erp_obligation_id' => $hidden->id, 'amount' => '60.0000', 'posted_at' => now()]);
        $allocation = ErpPaymentAllocation::create(['erp_payment_id' => $visible->id, 'erp_obligation_id' => $obligation->id, 'amount' => '7.0000', 'posted_at' => now()]);
        ErpCashMovement::factory()->forSession($session)->create($base + ['direction' => 'in', 'kind' => 'payment', 'amount' => '100.0000', 'erp_payment_id' => $payment->id]);
        $cash = ErpCashMovement::factory()->forSession($session)->create($base + ['direction' => 'in', 'kind' => 'payment', 'amount' => '7.0000', 'erp_payment_id' => $visible->id]);
        $membership = ErpCompanyMembership::query()->where('user_id', $this->user->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach([$this->establishment->id, $second->id], ['erp_company_id' => $this->company->id]);

        return ['membership' => $membership, 'hidden_establishment_id' => $second->id, 'payment' => $payment, 'visible_payment' => $visible,
            'allocation' => $allocation, 'cash' => $cash,
            'filters' => ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString(), 'erp_establishment_id' => $this->establishment->id]];
    }

    private function assertVisiblePaymentLedgers(array $report, string $category, array $fixture): void
    {
        $ledgers = $category === 'overview' ? $report['ledgers']['financial'] : $report['ledgers'];
        $totals = $category === 'overview' ? $report['sections']['financial'] : $report['totals'];
        $this->assertSame('7.0000', $totals['payments_posted_total']);
        $this->assertSame([$fixture['visible_payment']->id], array_column($ledgers['payments']['rows'], 'id'));
        $this->assertSame([$fixture['allocation']->id], array_column($ledgers['allocations']['rows'], 'id'));
        $this->assertSame([$fixture['cash']->id], array_column($ledgers['cash']['rows'], 'id'));
        foreach (['payments', 'allocations', 'cash'] as $name) {
            $this->assertSame(1, $ledgers[$name]['row_count']);
        }
        $this->assertSame('7.0000', $ledgers['payments']['totals']['amount']);
        $this->assertSame('7.0000', $ledgers['payments']['totals']['net_amount']);
        $this->assertSame(['in' => '7.0000'], $ledgers['payments']['totals']['by_direction']);
        $this->assertSame('7.0000', $ledgers['allocations']['totals']['amount']);
        $this->assertSame('7.0000', $ledgers['cash']['totals']['net_amount']);
        $this->assertSame(['7.0000'], array_values($ledgers['cash']['totals']['by_group']));
    }
}
