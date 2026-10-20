<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpCashLedgerTest extends TestCase
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

    private function open(string $opening = '100', string $currency = 'BRL'): int
    {
        $register = $this->send('cash-registers', ['name' => 'Caixa', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');

        return $this->send('cash-sessions', ['erp_cash_register_id' => $register, 'currency' => $currency, 'opening_amount' => $opening])->assertCreated()->json('data.id');
    }

    public function test_manual_movements_cannot_forge_payment_links_or_overdraw_physical_cash(): void
    {
        $session = $this->open('10');
        foreach ([['kind' => 'payment', 'direction' => 'in'], ['kind' => 'reversal', 'direction' => 'in'], ['kind' => 'deposit', 'direction' => 'out'], ['kind' => 'withdrawal', 'direction' => 'in'], ['kind' => 'deposit', 'direction' => 'in', 'erp_payment_id' => 1]] as $body) {
            $this->send("cash-sessions/{$session}/movements", $body + ['amount' => '1', 'reason' => 'Manual'])->assertUnprocessable();
        }
        $this->send("cash-sessions/{$session}/movements", ['kind' => 'withdrawal', 'direction' => 'out', 'amount' => '10.0001', 'reason' => 'Excesso'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('erp_cash_movements', 0);
        $this->send('payments', ['direction' => 'out', 'amount' => '11', 'method' => 'cash', 'erp_cash_session_id' => $session, 'erp_establishment_id' => $this->establishment->id])->assertUnprocessable();
        $this->assertDatabaseCount('erp_payments', 0);
        $this->getJson("/api/v1/erp/cash-sessions/{$session}/movements")->assertOk()->assertJsonPath('totals.expected_balance', '10.0000');
    }

    public function test_cash_failure_and_final_session_fence_roll_back_payment_movement_and_receipt(): void
    {
        $session = $this->open();
        $count = ErpOperationReceipt::count();
        $body = ['direction' => 'in', 'amount' => '30', 'method' => 'cash', 'erp_cash_session_id' => $session, 'erp_establishment_id' => $this->establishment->id];
        ErpCashMovement::created(function (): void {
            abort(422, 'After cash');
        });
        try {
            $this->send('payments', $body, 'cash-fail')->assertUnprocessable();
        } finally {
            ErpCashMovement::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_cash_movements', 0);
        $this->assertDatabaseCount('erp_payments', 0);
        $this->assertSame($count, ErpOperationReceipt::count());
        ErpOperationReceipt::created(function (): void {
            app('request')->attributes->set('access.session_lock', new class
            {
                public function isOwnedByCurrentProcess(): bool
                {
                    return false;
                }
            });
        });
        try {
            $this->send('payments', $body, 'cash-fail')->assertStatus(503);
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_cash_movements', 0);
        $this->assertDatabaseCount('erp_payments', 0);
        $this->assertSame($count, ErpOperationReceipt::count());
        $this->send('payments', $body, 'cash-fail')->assertCreated();
        $this->assertDatabaseCount('erp_cash_movements', 1);
    }

    public function test_close_retry_is_original_and_closed_session_rejects_late_effect_without_receipt(): void
    {
        $session = $this->open();
        $body = ['direction' => 'in', 'amount' => '3', 'method' => 'cash', 'erp_cash_session_id' => $session, 'erp_establishment_id' => $this->establishment->id];
        $close = $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '100'], 'closed')->assertOk();
        $this->send('payments', $body, 'late')->assertUnprocessable();
        $this->assertDatabaseCount('erp_payments', 0);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.store', ['Idempotency-Key' => 'late'])->assertNotFound();
        $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '100.0000'], 'closed')->assertOk()->assertExactJson($close->json());
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.cash-sessions.close', ['Idempotency-Key' => 'closed'])->assertOk()->assertJsonPath('data.result.expected_closing_amount', '100.0000');
        $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '100', 'close_operation_key' => 'different'], 'closed')->assertUnprocessable();
    }

    public function test_frozen_currency_and_cashier_authority_are_checked_for_new_effect_and_replay(): void
    {
        $session = $this->open();
        $body = ['direction' => 'in', 'amount' => '3', 'method' => 'cash', 'erp_cash_session_id' => $session, 'erp_establishment_id' => $this->establishment->id];
        $this->send('payments', $body + ['currency' => 'USD'], 'wrong-currency')->assertUnprocessable();
        $this->send('payments', $body, 'cash-authority')->assertCreated();
        $other = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $other->id, 'role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write']]);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->send('payments', $body)->assertForbidden();
        $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '103'])->assertForbidden();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.store', ['Idempotency-Key' => 'cash-authority'])->assertNotFound();
    }

    public function test_legacy_unknown_direction_and_currency_remain_explicit_and_raw_key_cannot_be_adopted(): void
    {
        $payment = ErpPayment::create(['erp_company_id' => $this->company->id, 'amount' => '10', 'method' => 'manual', 'source_type' => 'manual', 'source_id' => 0, 'source_operation_key' => 'legacy']);
        $this->getJson('/api/v1/erp/payments/'.$payment->id)->assertOk()->assertJsonPath('data.direction', null)->assertJsonPath('data.needs_reconciliation', true);
        $this->send('payments', ['amount' => '10', 'method' => 'manual', 'direction' => 'in'], 'legacy')->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->send("payments/{$payment->id}/reverse", ['reason' => 'Histórico'])->assertConflict();
        $obligation = $this->obligation('10');
        $this->send("payments/{$payment->id}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '10'])->assertConflict();
        $session = $this->open();
        ErpCashSession::whereKey($session)->update(['currency' => null]);
        $this->getJson("/api/v1/erp/cash-sessions/{$session}")->assertOk()->assertJsonPath('data.expected_balance', null)->assertJsonPath('data.needs_reconciliation', true);
        $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '100'])->assertUnprocessable();
    }

    #[DataProvider('invalidLedgerRelations')]
    public function test_new_cash_rows_reject_foreign_reversal_and_wrong_register_at_database_boundary(string $invalid): void
    {
        $session = ErpCashSession::findOrFail($this->open());
        $original = ErpCashMovement::factory()->forSession($session)->create();
        $candidate = $original->replicate();
        $candidate->source_operation_key = 'relational-proof';
        if ($invalid === 'wrong_register') {
            $candidate->erp_cash_register_id = ErpCashRegister::create(['erp_company_id' => $session->erp_company_id, 'erp_establishment_id' => $session->erp_establishment_id, 'name' => 'Other'])->id;
        } else {
            $otherCompany = ErpCompany::factory()->create();
            $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $otherCompany->id]);
            $register = ErpCashRegister::create(['erp_company_id' => $otherCompany->id, 'erp_establishment_id' => $establishment->id, 'name' => 'Foreign']);
            $foreignSession = ErpCashSession::create(['erp_company_id' => $otherCompany->id, 'erp_establishment_id' => $establishment->id, 'erp_cash_register_id' => $register->id, 'currency' => 'BRL', 'operator_user_id' => $session->operator_user_id, 'opened_by_user_id' => $session->operator_user_id, 'opened_at' => now()]);
            $candidate->reversal_of_id = ErpCashMovement::factory()->forSession($foreignSession)->create()->id;
            $candidate->kind = 'reversal';
            $candidate->direction = 'out';
        }
        $this->expectException(QueryException::class);
        $candidate->save();
    }

    public static function invalidLedgerRelations(): array
    {
        return [['foreign_reversal'], ['wrong_register']];
    }

    public function test_closed_original_reversal_checks_both_locations_but_only_current_target_cashier(): void
    {
        $source = $this->open();
        $payment = $this->payment('10', 'in', ['method' => 'cash', 'erp_cash_session_id' => $source]);
        $this->send("cash-sessions/{$source}/close", ['declared_closing_amount' => '110'])->assertOk();
        $other = User::factory()->create();
        ErpCashSession::whereKey($source)->update(['operator_user_id' => $other->id]);
        $target = $this->open();
        $this->send("payments/{$payment}/reverse", ['reason' => 'Correção no caixa atual', 'erp_cash_session_id' => $target], 'two-sessions')->assertOk();
        $this->send("cash-sessions/{$target}/close", ['declared_closing_amount' => '90'])->assertOk();
        $this->send("payments/{$payment}/reverse", ['reason' => 'Correção no caixa atual', 'erp_cash_session_id' => $target], 'two-sessions')->assertOk();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.reverse', ['Idempotency-Key' => 'two-sessions'])->assertOk();
        $this->assertSame('110.0000', ErpCashSession::findOrFail($source)->expected_closing_amount);
        $this->assertSame('90.0000', ErpCashSession::findOrFail($target)->expected_closing_amount);
    }

    public function test_manual_movement_journal_original_result_and_register_inactivation_do_not_repost_cash(): void
    {
        $session = $this->open();
        $body = ['kind' => 'deposit', 'direction' => 'in', 'amount' => '10', 'reason' => 'Suprimento'];
        $first = $this->send("cash-sessions/{$session}/movements", $body, 'deposit')->assertCreated();
        $register = ErpCashSession::findOrFail($session)->erp_cash_register_id;
        ErpCashRegister::whereKey($register)->update(['status' => 'archived']);
        $this->send("cash-sessions/{$session}/movements", $body, 'deposit')->assertCreated()->assertExactJson($first->json());
        $this->send("cash-sessions/{$session}/movements", [...$body, 'amount' => '11'], 'deposit')->assertConflict();
        $this->send("cash-sessions/{$session}/movements", $body, 'new-deposit')->assertUnprocessable();
        $this->assertDatabaseCount('erp_cash_movements', 1);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.cash-sessions.movements.store', ['Idempotency-Key' => 'deposit'])->assertOk()->assertJsonPath('data.result.id', $first->json('data.id'));
    }

    public function test_cash_session_totals_do_not_disclose_a_reversal_source_after_source_scope_is_revoked(): void
    {
        $source = $this->open();
        $payment = $this->payment('10', 'in', ['method' => 'cash', 'erp_cash_session_id' => $source]);
        $this->send("cash-sessions/{$source}/close", ['declared_closing_amount' => '110'])->assertOk();
        $targetLocation = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $register = $this->send('cash-registers', ['name' => 'Outro local', 'erp_establishment_id' => $targetLocation->id])->assertCreated()->json('data.id');
        $target = $this->send('cash-sessions', ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '100'])->assertCreated()->json('data.id');
        $this->send("payments/{$payment}/reverse", ['reason' => 'Compensação entre caixas', 'erp_cash_session_id' => $target])->assertOk();
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach($targetLocation->id, ['erp_company_id' => $this->company->id]);
        $this->getJson("/api/v1/erp/cash-sessions/{$target}")->assertNotFound();
        $this->getJson("/api/v1/erp/cash-sessions/{$target}/movements")->assertNotFound();
        $this->getJson('/api/v1/erp/cash-sessions')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_compensation_payment_and_target_cash_inherit_hidden_original_allocation_scope(): void
    {
        $source = $this->open();
        $payment = $this->payment('10', 'in', ['method' => 'cash', 'erp_cash_session_id' => $source]);
        $hidden = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $obligation = $this->send('obligations', ['amount' => '10', 'direction' => 'receivable', 'category' => 'manual', 'erp_establishment_id' => $hidden->id])->assertCreated()->json('data.id');
        $this->send("payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '10'])->assertOk();
        $this->send("cash-sessions/{$source}/close", ['declared_closing_amount' => '110'])->assertOk();
        $targetLocation = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $register = $this->send('cash-registers', ['name' => 'Target', 'erp_establishment_id' => $targetLocation->id])->assertCreated()->json('data.id');
        $target = $this->send('cash-sessions', ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '100'])->assertCreated()->json('data.id');
        $reversal = $this->send("payments/{$payment}/reverse", ['reason' => 'Source linkage', 'erp_cash_session_id' => $target])->assertOk()->json('data.id');
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach([$this->establishment->id, $targetLocation->id], ['erp_company_id' => $this->company->id]);
        foreach ([$payment, $reversal] as $id) {
            $this->getJson("/api/v1/erp/payments/{$id}")->assertNotFound();
            $this->getJson("/api/v1/erp/payments/{$id}/receipt")->assertNotFound();
        }
        $this->getJson('/api/v1/erp/payments?status=compensation')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'meta.totals');
        $this->getJson("/api/v1/erp/cash-sessions/{$target}")->assertNotFound();
        $this->getJson("/api/v1/erp/cash-sessions/{$target}/movements")->assertNotFound();
        $this->getJson('/api/v1/erp/cash-sessions')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_cash_rejects_balance_overflow_before_effect_and_keeps_exact_wide_turnover_readable(): void
    {
        $session = $this->open('900000000000000000');
        $before = ErpOperationReceipt::count();
        $this->send("cash-sessions/{$session}/movements", ['direction' => 'in', 'kind' => 'deposit', 'amount' => '900000000000000000', 'reason' => 'Overflow'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('erp_cash_movements', 0);
        $this->assertSame($before, ErpOperationReceipt::count());
        foreach ([['withdrawal', 'out'], ['deposit', 'in'], ['withdrawal', 'out'], ['deposit', 'in']] as [$kind, $direction]) {
            $this->send("cash-sessions/{$session}/movements", ['direction' => $direction, 'kind' => $kind, 'amount' => '900000000000000000', 'reason' => 'Turnover'])->assertCreated();
        }
        $this->getJson("/api/v1/erp/cash-sessions/{$session}")->assertOk()->assertJsonPath('data.movements_in', '1800000000000000000.0000')->assertJsonPath('data.movements_out', '1800000000000000000.0000')->assertJsonPath('data.expected_balance', '900000000000000000.0000');
        $this->send("cash-sessions/{$session}/close", ['declared_closing_amount' => '900000000000000000'])->assertOk()->assertJsonPath('data.closing_difference', '0.0000');
    }

    public function test_close_alias_uses_original_bytes_before_trim_and_cannot_conflict_with_header(): void
    {
        $session = $this->open();
        $count = ErpOperationReceipt::count();
        $path = "/api/v1/erp/cash-sessions/{$session}/close";
        foreach ([[' alias ', 'alias'], [' alias ', null], ['different', 'alias']] as [$alias, $header]) {
            $this->postJson($path, ['declared_closing_amount' => '100', 'close_operation_key' => $alias], $header === null ? [] : ['Idempotency-Key' => $header])->assertUnprocessable()->assertJsonValidationErrors('close_operation_key');
        }
        $this->postJson($path, ['declared_closing_amount' => '100', 'close_operation_key' => 'alias'], ['Idempotency-Key' => ['alias', 'alias']])->assertUnprocessable();
        $this->assertSame('open', ErpCashSession::findOrFail($session)->status);
        $this->assertSame($count, ErpOperationReceipt::count());
        $first = $this->postJson($path, ['declared_closing_amount' => '100', 'close_operation_key' => 'alias'])->assertOk();
        $this->postJson($path, ['declared_closing_amount' => '100', 'close_operation_key' => 'alias'], ['Idempotency-Key' => 'alias'])->assertOk()->assertExactJson($first->json());
    }
}
