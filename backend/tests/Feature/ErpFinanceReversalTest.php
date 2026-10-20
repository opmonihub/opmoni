<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ErpFinanceReversalTest extends TestCase
{
    use RefreshDatabase;

    private ErpCompany $company;

    private ErpEstablishment $establishment;

    private ErpProductVariant $variant;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Stock feature tests require SQLite memory before RefreshDatabase.');
        }
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $user = User::factory()->create();
        $this->company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $user->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $this->company->id, 'erp_unit_id' => $unit->id, 'kind' => 'goods', 'track_stock' => true]);
        $this->variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $this->company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
    }

    private int $sequence = 0;

    private function send(string $path, array $body, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/erp/'.$path, $body, ['Idempotency-Key' => $key ?? 'finance-'.++$this->sequence]);
    }

    private function obligation(string $amount = '100', string $direction = 'receivable'): int
    {
        return $this->send('obligations', ['amount' => $amount, 'direction' => $direction, 'category' => 'manual', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');
    }

    private function payment(string $amount = '100', string $direction = 'in', array $extra = []): int
    {
        return $this->send('payments', $extra + ['amount' => $amount, 'direction' => $direction, 'method' => 'manual', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');
    }

    public function test_http_partial_then_full_allocation_compensation_restores_exact_balance_and_original_replay(): void
    {
        $obligation = $this->obligation();
        $payment = $this->payment();
        $allocation = $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '100'])->assertOk()->json('data.id');
        $original = ErpPaymentAllocation::findOrFail($allocation)->getAttributes();
        $first = $this->send("payment-allocations/{$allocation}/reverse", ['amount' => '30', 'reason' => 'Correção parcial'], 'partial')->assertOk();
        $first->assertJsonPath('data.obligation_open_amount', '30.0000');
        $this->send("payment-allocations/{$allocation}/reverse", ['reason' => 'Restante'], 'remaining')->assertOk()->assertJsonPath('data.obligation_open_amount', '100.0000');
        $this->send("payment-allocations/{$allocation}/reverse", ['amount' => '30.0000', 'reason' => 'Correção parcial'], 'partial')->assertOk()->assertExactJson($first->json());
        $this->send("payment-allocations/{$allocation}/reverse", ['amount' => '31', 'reason' => 'Correção parcial'], 'partial')->assertConflict();
        $this->send("payment-allocations/{$allocation}/reverse", ['amount' => '1', 'reason' => 'Excesso'])->assertUnprocessable();
        $this->assertSame($original, ErpPaymentAllocation::findOrFail($allocation)->getAttributes());
        $this->getJson("/api/v1/erp/payments/{$payment}")->assertOk()->assertJsonPath('data.available_amount', '100.0000');
        $this->getJson("/api/v1/erp/payments/{$payment}/allocations")->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_new_payment_requires_explicit_direction_and_manual_creates_have_durable_original_lookup(): void
    {
        $this->send('payments', ['amount' => '1', 'method' => 'manual'])->assertUnprocessable()->assertJsonValidationErrors('direction');
        foreach ([['financial-accounts', ['name' => 'Conta', 'currency' => 'BRL']], ['cash-registers', ['name' => 'Caixa', 'erp_establishment_id' => $this->establishment->id]], ['obligations', ['amount' => '12', 'direction' => 'payable', 'category' => 'manual']]] as [$path, $body]) {
            $first = $this->send($path, $body, 'create-'.$path)->assertCreated();
            $this->send($path, $body, 'create-'.$path)->assertCreated()->assertExactJson($first->json());
            $this->getJson('/api/v1/erp/operations/lookup?operation=erp.'.$path.'.store', ['Idempotency-Key' => 'create-'.$path])->assertOk()->assertJsonPath('data.result.id', $first->json('data.id'));
            $this->send($path, $body + ['unexpected' => 'changed'], 'create-'.$path)->assertUnprocessable();
            $changed = array_replace($body, array_key_exists('name', $body) ? ['name' => 'Changed'] : ['amount' => '13']);
            $this->send($path, $changed, 'create-'.$path)->assertConflict();
        }
    }

    public function test_cash_payment_and_count_use_accepted_movements_and_closed_reversal_uses_explicit_target(): void
    {
        $register = $this->send('cash-registers', ['name' => 'Caixa', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');
        $open = ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '100'];
        $session = $this->send('cash-sessions', $open)->assertCreated()->json('data.id');
        $this->send("cash-sessions/{$session}/movements", ['kind' => 'deposit', 'direction' => 'in', 'amount' => '20', 'reason' => 'Suprimento'])->assertCreated();
        $this->send("cash-sessions/{$session}/movements", ['kind' => 'withdrawal', 'direction' => 'out', 'amount' => '10', 'reason' => 'Sangria'])->assertCreated();
        $payment = $this->payment('30', 'in', ['method' => 'cash', 'erp_cash_session_id' => $session]);
        $close = $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '139'], 'close')->assertOk();
        $close->assertJsonPath('data.expected_closing_amount', '140.0000')->assertJsonPath('data.closing_difference', '-1.0000');
        $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '138'], 'close')->assertConflict();
        $this->send("payments/{$payment}/reverse", ['reason' => 'Correção'])->assertUnprocessable()->assertJsonValidationErrors('erp_cash_session_id');
        $target = $this->send('cash-sessions', $open)->assertCreated()->json('data.id');
        $this->send("payments/{$payment}/reverse", ['reason' => 'Correção', 'erp_cash_session_id' => $target], 'reverse-cash')->assertOk();
        $this->assertSame('140.0000', ErpCashSession::findOrFail($session)->expected_closing_amount);
        $this->getJson("/api/v1/erp/cash-sessions/{$target}")->assertOk()->assertJsonPath('data.expected_balance', '70.0000');
        $receipt = $this->getJson("/api/v1/erp/payments/{$payment}/receipt")->assertOk()->assertJsonPath('data.amount', '30.0000')->assertJsonPath('data.effective_status', 'reversed');
        $this->assertStringContainsString('no-store', $receipt->headers->get('Cache-Control'));
    }

    public function test_full_payment_reversal_compensates_only_surviving_allocations_and_preserves_principal(): void
    {
        $a = $this->obligation('40');
        $b = $this->obligation('60');
        $payment = $this->payment();
        $original = ErpPayment::findOrFail($payment)->getAttributes();
        $allocation = $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $a, 'amount' => '40'])->assertOk()->json('data.id');
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $b, 'amount' => '60'])->assertOk();
        $this->send("payment-allocations/{$allocation}/reverse", ['amount' => '10', 'reason' => 'Parcial'])->assertOk();
        $result = $this->send("payments/{$payment}/reverse", ['reason' => 'Cancelar lançamento'], 'full')->assertOk()->assertJsonPath('data.amount', '-100.0000');
        $this->assertSame($original, ErpPayment::findOrFail($payment)->getAttributes());
        $this->assertSame('40.0000', ErpObligation::findOrFail($a)->open_amount);
        $this->assertSame('60.0000', ErpObligation::findOrFail($b)->open_amount);
        $this->assertDatabaseCount('erp_payment_allocations', 5);
        $this->send("payments/{$payment}/reverse", ['reason' => 'Cancelar lançamento'], 'full')->assertOk()->assertExactJson($result->json());
        $this->send("payments/{$payment}/reverse", ['reason' => 'Outro'])->assertUnprocessable();
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $a, 'amount' => '1'])->assertUnprocessable();
        $this->getJson("/api/v1/erp/payments/{$payment}")->assertOk()->assertJsonPath('data.effective_status', 'reversed')->assertJsonPath('data.available_amount', '0.0000');
    }

    public function test_finance_filter_aggregates_cover_all_scoped_rows_and_expose_opposite_directions(): void
    {
        $first = $this->obligation('100');
        $second = $this->obligation('20', 'payable');
        $this->getJson('/api/v1/erp/obligations?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.totals.0.receivable_open', '100.0000')->assertJsonPath('meta.totals.0.payable_open', '20.0000')->assertJsonPath('meta.totals.0.net_exposure', '80.0000');
        $payment = $this->payment('10');
        $this->getJson('/api/v1/erp/obligations?eligible_for_payment_id='.$payment)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first);
        $this->getJson('/api/v1/erp/obligations?direction=payable')->assertOk()->assertJsonPath('data.0.id', $second);
        $this->getJson('/api/v1/erp/obligations?direction=wrong')->assertUnprocessable();
        $this->getJson('/api/v1/erp/payments?status=reversed')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/obligations?erp_establishment_id=999999')->assertUnprocessable();
    }

    public function test_paid_and_partly_paid_full_sale_return_consumes_positive_credit_preserving_original(): void
    {
        foreach (['20', '10'] as $paid) {
            $purchase = $this->postJson('/api/v1/erp/purchases', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_cost' => '5']]])->assertCreated()->json('data.id');
            $this->send("purchases/{$purchase}/post", [])->assertOk();
            $sale = $this->postJson('/api/v1/erp/sales', ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'SALE-'.$paid, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_price' => '10']]])->assertCreated()->json('data.id');
            $this->send("sales/{$sale}/post", [])->assertOk();
            $obligation = ErpObligation::where('source_type', 'erp_sale')->where('source_id', $sale)->sole();
            $payment = $this->payment($paid);
            $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation->id, 'amount' => $paid])->assertOk();
            $before = $obligation->fresh()->getAttributes();
            $allocations = ErpPaymentAllocation::orderBy('id')->get()->toArray();
            $return = $this->postJson('/api/v1/erp/sale-returns', ['erp_sale_id' => $sale, 'total_amount' => '20'])->assertCreated()->json('data.id');
            $this->send("sale-returns/{$return}/post", [], 'return-'.$paid)->assertOk();
            $credit = ErpObligation::where('return_of_id', $obligation->id)->sole();
            $this->assertSame('payable', $credit->direction);
            $this->assertSame('20.0000', $credit->amount);
            $this->assertSame('20.0000', $credit->open_amount);
            $this->assertSame($before, $obligation->fresh()->getAttributes());
            $this->assertSame($allocations, ErpPaymentAllocation::orderBy('id')->get()->toArray());
            $this->send("sale-returns/{$return}/post", [], 'return-'.$paid)->assertOk();
            $this->assertSame(1, ErpObligation::where('return_of_id', $obligation->id)->count());
        }
    }

    public function test_reversal_and_receipt_failure_roll_back_compensations_and_restored_balances(): void
    {
        $obligation = $this->obligation();
        $payment = $this->payment();
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '100'])->assertOk();
        $receiptCount = ErpOperationReceipt::count();
        ErpPaymentAllocation::created(function ($row): void {
            if ($row->reversal_of_id !== null) {
                abort(422, 'After compensation');
            }
        });
        try {
            $this->send("payments/{$payment}/reverse", ['reason' => 'Correção'], 'rollback')->assertUnprocessable();
        } finally {
            ErpPaymentAllocation::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_payments', 1);
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertSame('0.0000', ErpObligation::findOrFail($obligation)->open_amount);
        ErpOperationReceipt::created(function (): void {
            abort(503, 'After receipt');
        });
        try {
            $this->send("payments/{$payment}/reverse", ['reason' => 'Correção'], 'rollback')->assertStatus(503);
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_payments', 1);
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertSame($receiptCount, ErpOperationReceipt::count());
        $this->assertSame('0.0000', ErpObligation::findOrFail($obligation)->open_amount);
        $this->send("payments/{$payment}/reverse", ['reason' => 'Correção'], 'rollback')->assertOk();
    }

    public function test_receipt_read_authority_and_replay_lookup_are_checked_currently(): void
    {
        $payment = $this->payment();
        $foreign = ErpPayment::create(['erp_company_id' => ErpCompany::factory()->create()->id, 'amount' => '10', 'method' => 'manual', 'direction' => 'in']);
        $this->getJson("/api/v1/erp/payments/{$foreign->id}/receipt")->assertNotFound();
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.payments.show']]);
        $this->getJson("/api/v1/erp/payments/{$payment}")->assertOk();
        $this->getJson("/api/v1/erp/payments/{$payment}/receipt")->assertForbidden();
        $membership->update(['action_permissions' => ['erp.payments.receipt']]);
        $this->getJson("/api/v1/erp/payments/{$payment}/receipt")->assertOk();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.store', ['Idempotency-Key' => 'finance-1'])->assertForbidden();
        $membership->update(['action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $this->getJson("/api/v1/erp/payments/{$payment}/receipt")->assertNotFound();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.store', ['Idempotency-Key' => 'finance-1'])->assertNotFound();
        $membership->delete();
        $this->getJson("/api/v1/erp/payments/{$payment}/receipt")->assertForbidden();
    }

    public function test_allocate_rejects_raw_numeric_comma_excess_zero_precision_and_whitespace_before_writes(): void
    {
        $obligation = $this->obligation();
        $payment = $this->payment();
        $before = ErpOperationReceipt::count();
        foreach ([1, 1.0, '1,0000', '1.00000', ' 1 ', '1e0'] as $amount) {
            $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->assertDatabaseCount('erp_payment_allocations', 0);
        $this->assertSame($before, ErpOperationReceipt::count());
        $this->send('payments', ['amount' => ' 1 ', 'direction' => 'in', 'method' => 'manual'])->assertUnprocessable();
    }

    public function test_completed_v1_allocation_replays_original_without_rewriting_hash_or_granting_new_effect(): void
    {
        $obligation = $this->obligation();
        $payment = $this->payment();
        $body = ['erp_obligation_id' => $obligation, 'amount' => '1'];
        $first = $this->send("payments/{$payment}/allocate", $body, 'v1')->assertOk();
        $receipt = ErpOperationReceipt::where('operation', 'erp.payments.allocate')->sole();
        $legacyHash = hash('sha256', 'v1:'.json_encode(['amount' => '1.0000', 'erp_obligation_id' => $obligation, 'payment_id' => $payment], JSON_UNESCAPED_SLASHES));
        $receipt->update(['canonical_version' => 'v1', 'payload_hash' => $legacyHash]);
        $original = $receipt->fresh()->getAttributes();
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '1,0000'], 'v1')->assertOk()->assertExactJson($first->json());
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '2,0000'], 'v1')->assertConflict();
        $this->assertSame($original, $receipt->fresh()->getAttributes());
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '1,0000'], 'new-legacy')->assertUnprocessable();
        $this->send("payments/{$payment}/allocate", $body, 'v2')->assertOk();
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '1,0000'], 'v2')->assertUnprocessable();
        $this->assertSame('v2', ErpOperationReceipt::where('key_digest', hash('sha256', 'v2'))->sole()->canonical_version);
    }

    public function test_detail_and_receipt_cannot_leak_links_or_totals_from_a_newly_hidden_obligation(): void
    {
        $payment = $this->payment();
        $foreignLocation = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $obligation = $this->send('obligations', ['amount' => '100', 'direction' => 'receivable', 'category' => 'manual', 'erp_establishment_id' => $foreignLocation->id])->assertCreated()->json('data.id');
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '40'])->assertOk();
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach($this->establishment->id, ['erp_company_id' => $this->company->id]);
        $this->getJson("/api/v1/erp/payments/{$payment}")->assertNotFound();
        $this->getJson("/api/v1/erp/payments/{$payment}/receipt")->assertNotFound();
        $this->getJson('/api/v1/erp/payments')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'meta.totals');
    }

    public function test_payment_candidates_require_allocate_authority_without_obligation_index_permission(): void
    {
        $obligation = $this->obligation();
        $payment = $this->payment();
        ErpCompanyMembership::where('erp_company_id', $this->company->id)->update(['role' => 'operator', 'action_permissions' => ['erp.payments.allocate']]);
        $this->getJson('/api/v1/erp/obligations')->assertForbidden();
        $this->getJson("/api/v1/erp/payments/{$payment}/allocation-candidates")->assertOk()->assertJsonPath('data.0.id', $obligation);
    }

    public function test_new_allocations_match_source_party_in_addition_to_currency_direction_and_account(): void
    {
        $contactA = ErpContact::factory()->create(['erp_company_id' => $this->company->id]);
        $contactB = ErpContact::factory()->create(['erp_company_id' => $this->company->id]);
        $sale = ErpSale::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $contactA->id, 'invoice_no' => 'Party', 'status' => 'posted', 'total_amount' => '10']);
        $payment = ErpPayment::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'amount' => '10', 'direction' => 'in', 'method' => 'manual', 'source_type' => 'erp_sale', 'source_id' => $sale->id]);
        $obligation = ErpObligation::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $contactB->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '10', 'open_amount' => '10']);
        $this->send("payments/{$payment->id}/allocate", ['erp_obligation_id' => $obligation->id, 'amount' => '10'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
    }

    public function test_paid_purchase_return_and_credit_failure_keep_original_money_and_rollback_stock(): void
    {
        foreach (['10', '5'] as $paid) {
            $purchase = $this->postJson('/api/v1/erp/purchases', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_cost' => '5']]])->assertCreated()->json('data.id');
            $this->send("purchases/{$purchase}/post", [])->assertOk();
            $obligation = ErpObligation::where('source_type', 'erp_purchase')->where('source_id', $purchase)->sole();
            $payment = $this->payment($paid, 'out');
            $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation->id, 'amount' => $paid])->assertOk();
            $original = $obligation->fresh()->getAttributes();
            $stock = ErpStockBalance::where('erp_product_variant_id', $this->variant->id)->sole()->quantity_on_hand;
            $return = $this->postJson('/api/v1/erp/purchase-returns', ['erp_purchase_id' => $purchase, 'total_amount' => '10'])->assertCreated()->json('data.id');
            ErpObligation::created(function ($row): void {
                if ($row->return_of_id !== null) {
                    abort(422, 'After return credit');
                }
            });
            try {
                $this->send("purchase-returns/{$return}/post", [], 'return-purchase-'.$paid)->assertUnprocessable();
            } finally {
                ErpObligation::flushEventListeners();
            }
            $this->assertSame(0, ErpObligation::where('return_of_id', $obligation->id)->count());
            $this->assertSame($stock, ErpStockBalance::where('erp_product_variant_id', $this->variant->id)->sole()->quantity_on_hand);
            $this->assertDatabaseHas('erp_purchase_returns', ['id' => $return, 'status' => 'draft']);
            $this->send("purchase-returns/{$return}/post", [], 'return-purchase-'.$paid)->assertOk();
            $credit = ErpObligation::where('return_of_id', $obligation->id)->sole();
            $this->assertSame('receivable', $credit->direction);
            $this->assertSame('10.0000', $credit->amount);
            $this->assertSame($original, $obligation->fresh()->getAttributes());
            $this->assertSame($paid.'.0000', ErpPayment::findOrFail($payment)->amount);
            $this->assertDatabaseCount('erp_cash_movements', 0);
        }
    }

    public function test_api_filters_validate_dates_currency_account_and_preserve_full_currency_totals(): void
    {
        $account = $this->send('financial-accounts', ['name' => 'BRL', 'currency' => 'BRL', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');
        $this->send('obligations', ['amount' => '12.0001', 'direction' => 'receivable', 'category' => 'manual', 'due_on' => '2026-01-01', 'erp_establishment_id' => $this->establishment->id, 'erp_financial_account_id' => $account])->assertCreated();
        $this->send('obligations', ['amount' => '8', 'currency' => 'USD', 'direction' => 'receivable', 'category' => 'manual', 'erp_establishment_id' => $this->establishment->id])->assertCreated();
        $this->getJson('/api/v1/erp/obligations?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'meta.totals');
        $this->getJson('/api/v1/erp/obligations?due_on=2026-01-01&erp_financial_account_id='.$account)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.totals.0.amount', '12.0001');
        $this->getJson('/api/v1/erp/obligations?date_to=2099-01-01')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/erp/obligations?currency=USD')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.totals.0.currency', 'USD');
        $this->getJson('/api/v1/erp/obligations?date_from=2026-02-01&date_to=2026-01-01')->assertUnprocessable();
        $this->send('payments', ['amount' => '1', 'direction' => 'in', 'method' => 'manual', 'currency' => 'USD', 'erp_establishment_id' => $this->establishment->id, 'erp_financial_account_id' => $account])->assertUnprocessable()->assertJsonValidationErrors('erp_financial_account_id');
        $this->send('payments', ['amount' => '1', 'direction' => 'in', 'method' => 'manual', 'erp_establishment_id' => true])->assertUnprocessable();
    }

    public function test_payment_detail_exposes_accepted_cash_and_allocation_links_with_server_correction_guidance(): void
    {
        $register = $this->send('cash-registers', ['name' => 'Detalhe', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');
        $session = $this->send('cash-sessions', ['erp_cash_register_id' => $register, 'opening_amount' => '0', 'currency' => 'BRL'])->assertCreated()->json('data.id');
        $payment = $this->payment('10', 'in', ['method' => 'cash', 'erp_cash_session_id' => $session]);
        $obligation = $this->obligation('10');
        $allocation = $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '4'])->assertOk()->json('data.id');
        $movement = DB::table('erp_cash_movements')->where('erp_payment_id', $payment)->value('id');
        $this->getJson("/api/v1/erp/payments/{$payment}")->assertOk()->assertJsonPath('data.allocation_ids.0', $allocation)->assertJsonPath('data.cash_movement_ids.0', $movement)->assertJsonPath('data.correction.permission', 'erp.payments.reverse')->assertJsonPath('data.correction.requires_cash_target', false)->assertJsonPath('data.available_amount', '6.0000');
        $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '10'])->assertOk();
        $this->getJson("/api/v1/erp/payments/{$payment}")->assertOk()->assertJsonPath('data.correction.requires_cash_target', true);
        $this->getJson("/api/v1/erp/payments/{$payment}/receipt")->assertOk()->assertJsonPath('data.cash_movement_ids.0', $movement)->assertJsonPath('data.allocation_ids.0', $allocation);
    }

    public function test_allocation_effect_replay_and_lookup_share_whole_payment_scope_after_narrowing(): void
    {
        $payment = $this->payment();
        $hidden = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $a = $this->send('obligations', ['amount' => '100', 'direction' => 'receivable', 'category' => 'manual', 'erp_establishment_id' => $hidden->id])->assertCreated()->json('data.id');
        $b = $this->obligation();
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $a, 'amount' => '40'])->assertOk();
        $body = ['erp_obligation_id' => $b, 'amount' => '10'];
        $this->send("payments/{$payment}/allocate", $body, 'prior-visible')->assertOk();
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach($this->establishment->id, ['erp_company_id' => $this->company->id]);
        $before = ErpOperationReceipt::count();
        $this->getJson("/api/v1/erp/payments/{$payment}")->assertNotFound();
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $b, 'amount' => '1'], 'new-visible')->assertNotFound();
        $this->send("payments/{$payment}/allocate", $body, 'prior-visible')->assertNotFound();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate', ['Idempotency-Key' => 'prior-visible'])->assertNotFound();
        $this->assertSame($before, ErpOperationReceipt::count());
        $this->assertDatabaseCount('erp_payment_allocations', 2);
    }

    public function test_filtered_totals_exceed_single_row_width_without_losing_exactness(): void
    {
        $this->obligation('900000000000000000');
        $this->obligation('900000000000000000');
        $this->getJson('/api/v1/erp/obligations?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.totals.0.receivable_open', '1800000000000000000.0000')->assertJsonPath('meta.totals.0.net_exposure', '1800000000000000000.0000')->assertJsonPath('meta.totals.0.amount', '1800000000000000000.0000');
    }

    public function test_legacy_ancestry_rejects_missing_foreign_and_cycle_parents_but_reads_proven_deep_chains(): void
    {
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'amount' => '1', 'direction' => 'in', 'method' => 'manual'];
        $principal = ErpPayment::create($base);
        $first = ErpPayment::create($base + ['reversal_of_id' => $principal->id]);
        $deep = ErpPayment::create($base + ['reversal_of_id' => $first->id]);
        $this->getJson("/api/v1/erp/payments/{$deep->id}")->assertOk();
        $missing = ErpPayment::create($base + ['reversal_of_id' => 999999999]);
        $foreign = ErpPayment::create(array_replace($base, ['erp_company_id' => ErpCompany::factory()->create()->id, 'erp_establishment_id' => null]));
        $foreignChild = ErpPayment::create($base + ['reversal_of_id' => $foreign->id]);
        $cycleA = ErpPayment::create($base);
        $cycleB = ErpPayment::create($base + ['reversal_of_id' => $cycleA->id]);
        $cycleA->update(['reversal_of_id' => $cycleB->id]);
        $cycleDescendant = ErpPayment::create($base + ['reversal_of_id' => $cycleA->id]);
        foreach ([$missing, $foreignChild, $cycleA, $cycleB, $cycleDescendant] as $payment) {
            $this->getJson("/api/v1/erp/payments/{$payment->id}")->assertNotFound();
            $this->getJson("/api/v1/erp/payments/{$payment->id}/receipt")->assertNotFound();
        }
        $this->getJson('/api/v1/erp/payments')->assertOk()->assertJsonPath('meta.total', 3);
    }
}
