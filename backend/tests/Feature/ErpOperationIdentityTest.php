<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpOperationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.fiscal_certificate_relaxed_validation' => true,
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
        $company = ErpCompany::factory()->create();
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

    private function allocationContext(): array
    {
        $ctx = $this->ownerContext();
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()]);
        $obligation = ErpObligation::create(['erp_company_id' => $ctx['company']->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '100', 'open_amount' => '100'])->id;
        $payment = ErpPayment::create(['erp_company_id' => $ctx['company']->id, 'amount' => '100', 'method' => 'manual', 'direction' => 'in'])->id;

        return $ctx + ['payment' => $payment, 'obligation' => $obligation];
    }

    public function test_header_retry_returns_original_result_after_another_allocation(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '40'];
        $original = $this->postJson($path, $body, ['Idempotency-Key' => 'first'])->assertOk()->json();
        $this->assertSame('60.0000', $original['data']['obligation_open_amount']);
        $this->postJson($path, $body, ['Idempotency-Key' => 'first'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson($original);
        $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'], ['Idempotency-Key' => 'second'])->assertOk();
        $this->postJson($path, $body, ['Idempotency-Key' => 'first'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson($original);
        $this->getJson('/api/v1/erp/obligations/'.$ctx['obligation'])->assertOk()->assertJsonPath('data.open_amount', '50.0000');
        $this->assertDatabaseCount('erp_payment_allocations', 2);
    }

    public function test_lookup_preknown_key_and_normalized_payload_conflict(): void
    {
        $ctx = $this->allocationContext();
        $this->withHeader('Idempotency-Key', 'known');
        $lookup = '/api/v1/erp/operations/lookup?operation=erp.payments.allocate';
        $this->getJson($lookup)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('code', 'operation_not_recorded');
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $original = $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'])->assertOk()->json();
        $this->postJson($path, ['erp_obligation_id' => (string) $ctx['obligation'], 'amount' => '10.0000'])->assertOk()->assertExactJson($original);
        $this->getJson($lookup)->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result', $original['data']);
        $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => '11'])->assertConflict();
        $this->getJson('/api/v1/erp/operations/lookup?operation=arbitrary')->assertUnprocessable();
        $this->assertDatabaseCount('erp_payment_allocations', 1);
    }

    public function test_key_validation_preserves_original_alias_bytes_and_rejects_floats(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'];
        foreach ([' bad', 'bad key', 'a,b', str_repeat('a', 129)] as $key) {
            $this->postJson($path, $body, ['Idempotency-Key' => $key])->assertUnprocessable();
        }
        foreach (['other', ' known ', 123, ['known']] as $alias) {
            $this->postJson($path, $body + ['source_operation_key' => $alias], ['Idempotency-Key' => 'known'])->assertUnprocessable();
        }
        $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => 10.5], ['Idempotency-Key' => 'known'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
    }

    public function test_legacy_company_wide_collision_never_duplicates_or_invents_original_result(): void
    {
        $ctx = $this->allocationContext();
        $legacy = ErpPaymentAllocation::create([
            'erp_payment_id' => $ctx['payment'], 'erp_obligation_id' => $ctx['obligation'],
            'amount' => '10', 'source_operation_key' => 'old', 'posted_by_user_id' => $ctx['user']->id, 'posted_at' => now(),
        ]);
        $this->withHeader('Idempotency-Key', 'old');
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'])->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate')->assertOk()->assertJsonPath('data.state', 'legacy_observed')->assertJsonPath('data.result', null);
        $other = ErpPayment::create(['erp_company_id' => $ctx['company']->id, 'amount' => '100', 'method' => 'manual', 'direction' => 'in'])->id;
        $this->postJson('/api/v1/erp/payments/'.$other.'/allocate', ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'])->assertConflict();
        $legacy->update(['posted_by_user_id' => null]);
        $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'])->assertConflict();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate')->assertNotFound();
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_rendered_errors_after_effect_and_after_receipt_roll_back_the_whole_unit(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'];
        ErpPaymentAllocation::created(function (): void {
            abort(422, 'Injected after allocation');
        });
        $this->postJson($path, $body, ['Idempotency-Key' => 'after-effect'])->assertUnprocessable();
        ErpPaymentAllocation::flushEventListeners();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
        ErpOperationReceipt::created(function (): void {
            abort(503, 'Injected after receipt');
        });
        $this->postJson($path, $body, ['Idempotency-Key' => 'after-receipt'])->assertStatus(503);
        ErpOperationReceipt::flushEventListeners();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->assertSame('100.0000', ErpObligation::findOrFail($ctx['obligation'])->open_amount);
    }

    public function test_lookup_and_replay_require_current_action_release_subscription_and_resources(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '100'];
        $this->withHeader('Idempotency-Key', 'gated');
        $original = $this->postJson($path, $body)->assertOk()->json();
        $this->postJson($path, $body)->assertOk()->assertExactJson($original);
        $lookup = '/api/v1/erp/operations/lookup?operation=erp.payments.allocate';
        config(['erp.operating_writes_enabled' => false]);
        $this->getJson($lookup)->assertForbidden();
        $this->postJson($path, $body)->assertForbidden();
        config(['erp.operating_writes_enabled' => true]);
        ErpSubscription::where('erp_company_id', $ctx['company']->id)->update(['status' => 'canceled']);
        $this->getJson($lookup)->assertForbidden();
        ErpSubscription::where('erp_company_id', $ctx['company']->id)->update(['status' => 'active']);
        $this->getJson($lookup, ['X-Company-Id' => '9999'])->assertConflict();
        ErpCompanyMembership::where('user_id', $ctx['user']->id)->update(['role' => 'viewer']);
        $this->getJson($lookup)->assertForbidden();
        $this->assertDatabaseCount('erp_payment_allocations', 1);
    }

    public function test_foreign_obligation_is_a_field_error_and_no_key_remains_unprotected_legacy(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $this->postJson($path, ['erp_obligation_id' => 99999, 'amount' => '10'], ['Idempotency-Key' => 'foreign'])->assertUnprocessable()->assertJsonValidationErrors('erp_obligation_id');
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'];
        $this->postJson($path, $body)->assertOk();
        $this->postJson($path, $body)->assertOk();
        $this->assertDatabaseCount('erp_payment_allocations', 2);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_actor_company_namespaces_and_exact_action_lookup_authority(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'];
        $this->withHeader('Idempotency-Key', 'shared');
        $first = $this->postJson($path, $body)->assertOk()->json('data.id');
        $second = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $ctx['company']->id, 'user_id' => $second->id, 'role' => 'owner', 'action_permissions' => ['erp.payments.allocate']]);
        $this->login($second);
        $this->withHeader('X-CSRF-Token', $this->csrf());
        $lookup = '/api/v1/erp/operations/lookup?operation=erp.payments.allocate';
        $this->getJson($lookup)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('code', 'operation_not_recorded');
        $secondResult = $this->postJson($path, $body)->assertOk()->json();
        $this->assertNotSame($first, $secondResult['data']['id']);
        $this->getJson($lookup)->assertOk()->assertJsonPath('data.result', $secondResult['data']);
        ErpCompanyMembership::where('user_id', $second->id)->update(['action_permissions' => ['erp.payments.read']]);
        $this->getJson($lookup)->assertForbidden();
        $this->postJson($path, $body)->assertForbidden();
        $this->assertDatabaseCount('erp_operation_receipts', 2);

        $other = $this->allocationContext();
        $this->getJson($lookup)->assertNotFound();
        $this->postJson('/api/v1/erp/payments/'.$other['payment'].'/allocate', ['erp_obligation_id' => $other['obligation'], 'amount' => '10'], ['Idempotency-Key' => 'shared'])->assertOk();
        $this->assertDatabaseCount('erp_operation_receipts', 3);
    }

    public function test_path_and_obligation_changes_conflict_and_removed_source_cannot_leak_result(): void
    {
        $ctx = $this->allocationContext();
        $this->withHeader('Idempotency-Key', 'bound');
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'];
        $this->postJson($path, $body)->assertOk();
        $otherPayment = ErpPayment::create(['erp_company_id' => $ctx['company']->id, 'amount' => '100', 'method' => 'manual', 'direction' => 'in'])->id;
        $otherObligation = ErpObligation::create(['erp_company_id' => $ctx['company']->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '100', 'open_amount' => '100'])->id;
        $this->postJson('/api/v1/erp/payments/'.$otherPayment.'/allocate', $body)->assertConflict();
        $this->postJson($path, ['erp_obligation_id' => $otherObligation, 'amount' => '10'])->assertConflict();
        $foreign = ErpCompany::factory()->create();
        ErpPayment::whereKey($ctx['payment'])->update(['erp_company_id' => $foreign->id]);
        $this->postJson($path, $body)->assertNotFound();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate')->assertNotFound()->assertJsonMissingPath('data.result');
        $this->assertDatabaseCount('erp_payment_allocations', 1);
    }

    public function test_alias_only_matching_header_and_exact_legacy_resource_reference(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10', 'source_operation_key' => 'alias'];
        $original = $this->postJson($path, $body)->assertOk()->json();
        $this->postJson($path, $body, ['Idempotency-Key' => 'alias'])->assertOk()->assertExactJson($original);
        $legacy = ErpPaymentAllocation::create(['erp_payment_id' => $ctx['payment'], 'erp_obligation_id' => $ctx['obligation'], 'amount' => '10', 'source_operation_key' => 'old-ref', 'posted_by_user_id' => $ctx['user']->id, 'posted_at' => now()]);
        $response = $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'], ['Idempotency-Key' => 'old-ref'])->assertConflict();
        $response->assertJsonPath('resource.id', $legacy->id)->assertJsonMissingPath('result');
    }

    public function test_ambiguous_or_other_actor_legacy_keys_do_not_disclose_or_reallocate(): void
    {
        $ctx = $this->allocationContext();
        $second = ErpPayment::create(['erp_company_id' => $ctx['company']->id, 'amount' => '100', 'method' => 'manual', 'direction' => 'in'])->id;
        foreach ([$ctx['payment'], $second] as $payment) {
            ErpPaymentAllocation::create(['erp_payment_id' => $payment, 'erp_obligation_id' => $ctx['obligation'], 'amount' => '10', 'source_operation_key' => 'ambiguous', 'posted_by_user_id' => $ctx['user']->id, 'posted_at' => now()]);
        }
        $this->withHeader('Idempotency-Key', 'ambiguous');
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'];
        $this->postJson($path, $body)->assertConflict()->assertJsonMissingPath('resource');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate')->assertNotFound();
        ErpPaymentAllocation::where('erp_payment_id', $second)->delete();
        ErpPaymentAllocation::query()->update(['posted_by_user_id' => User::factory()->create()->id]);
        $this->postJson($path, $body)->assertConflict()->assertJsonMissingPath('resource');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate')->assertNotFound();
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_safe_result_size_failure_rolls_back_effect_and_original_balance(): void
    {
        $ctx = $this->allocationContext();
        ErpPaymentAllocation::created(function () use ($ctx): void {
            ErpObligation::whereKey($ctx['obligation'])->update(['status' => str_repeat('x', 17000)]);
        });
        $this->postJson('/api/v1/erp/payments/'.$ctx['payment'].'/allocate', ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'], ['Idempotency-Key' => 'oversized'])->assertUnprocessable();
        ErpPaymentAllocation::flushEventListeners();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->assertSame('100.0000', ErpObligation::findOrFail($ctx['obligation'])->open_amount);
        $this->assertSame('open', ErpObligation::findOrFail($ctx['obligation'])->status);
    }

    public function test_form_alias_whitespace_is_not_silently_transformed_by_global_trim(): void
    {
        $ctx = $this->allocationContext();
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10', 'source_operation_key' => ' trimmed '];
        $this->call('POST', '/api/v1/erp/payments/'.$ctx['payment'].'/allocate', $body, [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_OPMONI_INGRESS_KEY' => 'test-key', 'HTTP_X_OPMONI_CLIENT_IP' => '127.0.0.1', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http',
            'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_COMPANY_ID' => (string) $ctx['company']->id, 'HTTP_X_CSRF_TOKEN' => $this->csrf(),
        ], http_build_query($body))->assertUnprocessable();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
    }

    public function test_orphan_internal_key_collision_requires_reconciliation(): void
    {
        $ctx = $this->allocationContext();
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $body = ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'];
        $this->postJson($path, $body, ['Idempotency-Key' => 'orphan'])->assertOk();
        ErpOperationReceipt::query()->delete();
        $this->postJson($path, $body, ['Idempotency-Key' => 'orphan'])->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public static function malformedIdentifierTypes(): array
    {
        return ['boolean' => [true], 'integral float' => [1.0]];
    }

    #[DataProvider('malformedIdentifierTypes')]
    public function test_malformed_identifier_types_cannot_create_an_allocation(bool|float $identifier): void
    {
        $ctx = $this->allocationContext();
        $this->assertSame(1, $ctx['obligation']);
        $this->postJson('/api/v1/erp/payments/'.$ctx['payment'].'/allocate', [
            'erp_obligation_id' => $identifier, 'amount' => '10',
        ], ['Idempotency-Key' => 'invalid-identifier'], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors('erp_obligation_id')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('erp_payment_allocations', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->assertSame('100.0000', ErpObligation::findOrFail($ctx['obligation'])->open_amount);
    }

    #[DataProvider('malformedIdentifierTypes')]
    public function test_malformed_identifier_types_cannot_replay_an_existing_receipt(bool|float $identifier): void
    {
        $ctx = $this->allocationContext();
        $this->assertSame(1, $ctx['obligation']);
        $path = '/api/v1/erp/payments/'.$ctx['payment'].'/allocate';
        $this->withHeader('Idempotency-Key', 'existing-identifier');
        $original = $this->postJson($path, ['erp_obligation_id' => $ctx['obligation'], 'amount' => '10'])->assertOk()->json();
        $this->postJson($path, ['erp_obligation_id' => $identifier, 'amount' => '10'], [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors('erp_obligation_id')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $this->assertSame('90.0000', ErpObligation::findOrFail($ctx['obligation'])->open_amount);
        $this->postJson($path, ['erp_obligation_id' => (string) $ctx['obligation'], 'amount' => '10.0000'])->assertOk()->assertExactJson($original);
    }
}
