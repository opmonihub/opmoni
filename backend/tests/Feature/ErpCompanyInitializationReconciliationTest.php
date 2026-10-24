<?php

namespace Tests\Feature;

use App\Erp\ErpCompanyInitializationReconciliation;
use App\Erp\ErpCompanyInitializer;
use App\Erp\Sales\ErpCommercialNumbers;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpInvoiceScheme;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpCompanyInitializationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIEW = '/api/v1/erp/company/initialization/preview';

    private const APPLY = '/api/v1/erp/company/initialization/apply';

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null, 'erp.operating_writes_enabled' => true]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    private function login(User $user, ErpCompany $company): void
    {
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
    }

    private function context(array $settings = []): array
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['operating_settings' => $settings]);
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'status' => 'active']);
        $this->login($user, $company);

        return compact('user', 'company', 'membership', 'establishment');
    }

    private function preview(): array
    {
        return $this->postJson(self::PREVIEW)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function applyBody(array $preview): array
    {
        return ['preview_version' => $preview['preview_version'], 'fingerprint' => $preview['fingerprint']];
    }

    private function item(array $preview, string $key): array
    {
        $items = array_column($preview['items'], null, 'key');
        $this->assertArrayHasKey($key, $items);

        return $items[$key];
    }

    public function test_apply_service_cannot_bypass_protected_write_boundary(): void
    {
        $ctx = $this->context();
        $request = Request::create(self::APPLY, 'POST');
        $request->attributes->set(CurrentCompany::class, new CurrentCompany($ctx['company'], 'direct', $ctx['membership'], null, null));
        $request->setUserResolver(fn (): User => $ctx['user']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Catalog writes require the protected catalog transaction.');
        app(ErpCompanyInitializationReconciliation::class)->apply($request);
    }

    public function test_lookup_requires_current_owner_even_before_any_receipt_exists(): void
    {
        $ctx = $this->context();
        $headers = ['Idempotency-Key' => 'not-recorded'];
        $lookup = '/api/v1/erp/operations/lookup?operation=erp.company.initialization.apply';
        $this->getJson($lookup, $headers)->assertNotFound()->assertJsonPath('code', 'operation_not_recorded');
        $ctx['membership']->update(['role' => 'manager', 'action_permissions' => ['*']]);
        $this->getJson($lookup, $headers)->assertForbidden()->assertJsonMissingPath('data');
    }

    public function test_preview_reports_effective_fallback_delta_without_persisting_anything_or_exposing_secrets(): void
    {
        $ctx = $this->context(['csc' => 'secret-sentinel', 'email_settings' => ['password' => 'private-password'], 'custom_labels' => ['nested' => ['zero' => 0]]]);
        $before = $ctx['company']->fresh()->getRawOriginal();
        $response = $this->postJson(self::PREVIEW)->assertOk();
        $preview = $response->json('data');

        $this->assertSame('br-basic-v1', $preview['preset_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['fingerprint']);
        $time = $this->item($preview, 'operating_settings.time_format');
        $this->assertSame('create', $time['status']);
        $this->assertSame(12, $time['before']);
        $this->assertSame(24, $time['after']);
        $this->assertTrue($time['effective_changed']);
        $this->assertSame('create', $this->item($preview, 'unit')['status']);
        $this->assertIsArray($this->item($preview, 'unit')['after']);
        $this->assertSame('UN', $this->item($preview, 'unit')['after']['short_name']);
        $this->assertSame('Unidade', $this->item($preview, 'unit')['after']['actual_name']);
        $this->assertFalse($this->item($preview, 'unit')['after']['allow_decimal']);
        $this->assertTrue($this->item($preview, 'unit')['effective_changed']);
        $this->assertFalse($preview['sale_numbering_adopted']);

        $this->assertStringNotContainsString('secret-sentinel', $response->getContent());
        $this->assertStringNotContainsString('private-password', $response->getContent());
        $this->assertStringNotContainsString('email_settings', $response->getContent());
        $this->assertSame($before, $ctx['company']->fresh()->getRawOriginal());
        foreach (['erp_company_setup_states', 'erp_units', 'erp_contacts', 'erp_invoice_schemes', 'erp_invoice_layouts', 'erp_operation_receipts', 'erp_catalog_attributions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_customized_un_and_opaque_currency_are_conflicts_and_never_adopted(): void
    {
        $ctx = $this->context(['currency_id' => 987, 'purchase_currency_id' => 456, 'default_unit' => 123]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id, 'short_name' => 'UN', 'actual_name' => 'Pacote particular', 'allow_decimal' => true, 'status' => 'archived']);
        $existingCustomer = ErpContact::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Consumidor final']);
        $preview = $this->preview();
        $this->assertSame('conflict', $this->item($preview, 'unit')['status']);
        $this->assertSame('conflict', $this->item($preview, 'currency')['status']);
        $this->assertTrue($preview['has_conflicts']);
        $result = $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'conflicted'])->assertOk()->json('data');
        $this->assertTrue($result['has_conflicts']);
        $this->assertFalse($result['completed']);
        $state = $ctx['company']->setupState()->firstOrFail();
        $this->assertNull($state->currency);
        $this->assertNull($state->default_unit_id);
        $this->assertNotSame($existingCustomer->id, $state->default_customer_id);
        $this->assertNull($existingCustomer->fresh()->native_key);
        $this->assertSame('Pacote particular', $unit->fresh()->actual_name);
        $this->assertTrue($unit->fresh()->allow_decimal);
        $this->assertSame('archived', $unit->fresh()->status);
        $this->assertNull($unit->fresh()->native_key);
        $this->assertSame(987, $ctx['company']->fresh()->operating_settings['currency_id']);
        $nextPreview = $this->preview();
        $this->assertSame('conflict', $this->item($nextPreview, 'unit')['status']);
        $this->assertSame('conflict', $this->item($nextPreview, 'currency')['status']);
        $this->postJson(self::APPLY, $this->applyBody($nextPreview), ['Idempotency-Key' => 'conflicted-again'])->assertOk()->assertJsonPath('data.completed', false);

    }

    public function test_apply_preserves_all_explicit_empty_values_nested_settings_and_opaque_namespaces(): void
    {
        $existing = ['time_zone' => null, 'date_format' => '', 'time_format' => 0, 'fy_start_month' => [], 'stock_overselling_enabled' => false, 'currency_id' => null, 'purchase_currency_id' => 0, 'default_unit' => '', 'pos_settings' => ['nested' => ['null' => null, 'false' => false, 'zero' => 0, 'empty' => '', 'array' => []]], 'csc' => 'private-value'];
        $ctx = $this->context($existing);
        $preview = $this->preview();
        foreach (['time_zone', 'date_format', 'time_format', 'fy_start_month', 'stock_overselling_enabled'] as $key) {
            $this->assertSame('skip', $this->item($preview, 'operating_settings.'.$key)['status']);
        }
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'missing-only'])->assertOk();
        $this->assertSame([...$existing, 'accounting_method' => 'fifo'], $ctx['company']->fresh()->operating_settings);
        $this->assertDatabaseCount('erp_tax_rates', 0);
        $this->assertDatabaseCount('erp_operation_natures', 0);
        $this->assertDatabaseCount('erp_fiscal_configurations', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('accounts', 0);
        $receipt = ErpOperationReceipt::query()->firstOrFail();
        $encodedReceipt = json_encode([$receipt->response_body, $receipt->resource_refs], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-value', $encodedReceipt);
        $this->assertStringNotContainsString('pos_settings', $encodedReceipt);
    }

    public function test_compatible_native_records_are_reused_without_duplicate_baseline_or_replacing_explicit_null_pointers(): void
    {
        $ctx = $this->context();
        $state = app(ErpCompanyInitializer::class)->initialize($ctx['company']);
        $state->update(['currency' => null, 'default_customer_id' => null, 'revision' => 7]);
        $preview = $this->preview();
        $this->assertSame('reuse', $this->item($preview, 'unit')['status']);
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'existing-native'])->assertOk();
        $this->assertNull($state->fresh()->currency);
        $this->assertNull($state->fresh()->default_customer_id);
        foreach (['erp_units', 'erp_contacts', 'erp_invoice_schemes', 'erp_invoice_layouts', 'erp_company_setup_states'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
    }

    public function test_explicit_custom_native_defaults_are_preserved_without_claiming_preset_ownership(): void
    {
        $ctx = $this->context();
        $state = app(ErpCompanyInitializer::class)->initialize($ctx['company']);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id, 'short_name' => 'CX', 'actual_name' => 'Caixa', 'allow_decimal' => true]);
        $customer = ErpContact::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Cliente escolhido']);
        $scheme = ErpInvoiceScheme::query()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Custom', 'prefix' => 'CUSTOM-', 'start_number' => 42, 'total_digits' => 7, 'status' => 'active']);
        $layout = ErpInvoiceLayout::query()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Custom', 'layout_settings' => ['nested' => ['label' => 'Existing']], 'status' => 'active']);
        $state->update(['currency' => 'USD', 'default_unit_id' => $unit->id, 'default_customer_id' => $customer->id, 'default_invoice_scheme_id' => $scheme->id, 'default_invoice_layout_id' => $layout->id]);
        $before = $state->fresh()->getRawOriginal();
        $preview = $this->preview();
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'custom-defaults'])->assertOk();
        foreach (['currency', 'default_unit_id', 'default_customer_id', 'default_invoice_scheme_id', 'default_invoice_layout_id', 'sale_numbering_adopted', 'preset_owned_keys'] as $key) {
            $this->assertSame($before[$key], $state->fresh()->getRawOriginal($key), $key);
        }
        $this->assertNull($unit->fresh()->native_key);
        $this->assertNull($customer->fresh()->native_key);
        $this->assertNull($scheme->fresh()->native_key);
        $this->assertNull($layout->fresh()->native_key);
    }

    public function test_custom_default_contact_that_becomes_supplier_only_remains_a_persistent_conflict(): void
    {
        $ctx = $this->context();
        $state = app(ErpCompanyInitializer::class)->initialize($ctx['company']);
        $customer = ErpContact::factory()->create([
            'erp_company_id' => $ctx['company']->id, 'name' => 'Cliente escolhido',
            'is_customer' => true, 'is_supplier' => false, 'status' => 'active',
        ]);
        $state->update(['default_customer_id' => $customer->id]);
        $this->assertSame('reuse', $this->item($this->preview(), 'customer')['status']);
        $this->patchJson('/api/v1/erp/contacts/'.$customer->id, ['is_customer' => false, 'is_supplier' => true])->assertOk();
        $beforeContact = $customer->fresh()->getRawOriginal();
        $beforeState = $state->fresh()->getRawOriginal();

        foreach (['supplier-only', 'supplier-only-again'] as $key) {
            $preview = $this->preview();
            $this->assertSame('conflict', $this->item($preview, 'customer')['status']);
            $this->assertTrue($preview['has_conflicts']);
            $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => $key])
                ->assertOk()->assertJsonPath('data.completed', false)->assertJsonPath('data.has_conflicts', true);
            $this->assertSame($beforeState, $state->fresh()->getRawOriginal());
            $this->assertSame($beforeContact, $customer->fresh()->getRawOriginal());
            $this->assertNull($customer->fresh()->native_key);
            $this->assertSame($customer->id, $state->fresh()->default_customer_id);
        }
        $this->assertDatabaseCount('erp_contacts', 2);
    }

    public function test_archived_native_records_stay_conflicts_without_reactivation(): void
    {
        $ctx = $this->context();
        $state = app(ErpCompanyInitializer::class)->initialize($ctx['company']);
        $unit = $state->defaultUnit;
        $customer = $state->defaultCustomer;
        $scheme = $state->defaultInvoiceScheme;
        $layout = $state->defaultInvoiceLayout;
        foreach ([$unit, $customer, $scheme, $layout] as $record) {
            $record->update(['status' => 'archived']);
        }
        $preview = $this->preview();
        foreach (['unit', 'customer', 'sale_scheme', 'sale_layout'] as $key) {
            $this->assertSame('conflict', $this->item($preview, $key)['status']);
        }
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'archived'])->assertOk()->assertJsonPath('data.completed', false);
        foreach ([$unit, $customer, $scheme, $layout] as $record) {
            $this->assertSame('archived', $record->fresh()->status);
        }
    }

    public static function stalePreconditions(): array
    {
        return ['settings' => ['settings'], 'catalog' => ['catalog'], 'setup' => ['setup']];
    }

    #[DataProvider('stalePreconditions')]
    public function test_changed_preconditions_require_a_new_preview_and_do_not_leave_receipts(string $change): void
    {
        $ctx = $this->context();
        $preview = $this->preview();
        match ($change) {
            'settings' => $ctx['company']->update(['operating_settings' => ['time_format' => 12]]),
            'catalog' => ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id, 'short_name' => 'UN']),
            'setup' => app(ErpCompanyInitializer::class)->initialize($ctx['company']),
        };
        $before = $ctx['company']->fresh()->getRawOriginal();
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'stale'])->assertConflict()->assertJsonPath('code', 'initialization_preview_stale');
        $this->assertSame($before, $ctx['company']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_existing_setup_revision_and_selected_record_changes_invalidate_review(): void
    {
        $ctx = $this->context();
        $state = app(ErpCompanyInitializer::class)->initialize($ctx['company']);
        $preview = $this->preview();
        $state->update(['revision' => $state->revision + 1]);
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'revision-changed'])->assertConflict();
        $preview = $this->preview();
        $state->defaultUnit->update(['actual_name' => 'Unidade modificada']);
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'default-changed'])->assertConflict();
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_equal_retry_and_lookup_reuse_original_result_after_subsequent_settings_change(): void
    {
        $ctx = $this->context();
        $preview = $this->preview();
        $body = $this->applyBody($preview);
        $headers = ['Idempotency-Key' => 'lost-response'];
        $original = $this->postJson(self::APPLY, $body, $headers)->assertOk()->json();
        $ctx['company']->update(['operating_settings' => ['time_format' => 12]]);
        $this->postJson(self::APPLY, $body, $headers)->assertOk()->assertExactJson($original);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.company.initialization.apply', $headers)->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result', $original['data']);
        $this->assertSame(['time_format' => 12], $ctx['company']->fresh()->operating_settings);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $this->assertDatabaseCount('erp_units', 1);
        $this->postJson(self::APPLY, $this->applyBody($this->preview()), $headers)->assertConflict();
    }

    public function test_old_preview_cannot_be_substituted_by_another_actor_or_selected_company(): void
    {
        $ctx = $this->context();
        $preview = $this->preview();
        $second = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $ctx['company']->id, 'user_id' => $second->id, 'role' => 'owner']);
        $this->login($second, $ctx['company']);
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'another-actor'])->assertConflict();
        $other = $this->context();
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'another-company'])->assertConflict();
        $this->postJson(self::PREVIEW, [], ['X-Company-Id' => (string) $ctx['company']->id])->assertConflict()->assertJsonPath('code', 'company_context_changed');
        $this->assertDatabaseCount('erp_company_setup_states', 0);
    }

    public function test_current_owner_is_required_for_preview_apply_retry_and_operation_lookup(): void
    {
        $ctx = $this->context();
        $preview = $this->preview();
        $headers = ['Idempotency-Key' => 'revoked-owner'];
        $this->postJson(self::APPLY, $this->applyBody($preview), $headers)->assertOk();
        $ctx['membership']->update(['role' => 'manager', 'action_permissions' => ['*']]);
        $this->postJson(self::PREVIEW)->assertForbidden();
        $this->postJson(self::APPLY, $this->applyBody($preview), $headers)->assertForbidden();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.company.initialization.apply', $headers)->assertForbidden()->assertJsonMissingPath('data.result');
        $this->assertDatabaseCount('erp_operation_receipts', 1);
    }

    public function test_platform_role_and_delegation_never_supply_direct_ownership(): void
    {
        $ctx = $this->context();
        $ctx['user']->update(['is_super_admin' => true]);
        $ctx['membership']->delete();
        $this->postJson(self::PREVIEW)->assertForbidden()->assertJsonMissingPath('data');
        $account = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $ctx['user']->id, 'role' => 'admin']);
        ErpOfficeDelegation::factory()->create(['erp_company_id' => $ctx['company']->id, 'account_id' => $account->id, 'authorized_by_user_id' => $ctx['user']->id, 'status' => 'active', 'permission_allowlist' => ['erp.write']]);
        $this->postJson('/api/v1/account/switch', ['account_id' => $account->id])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $ctx['company']->id])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson(self::PREVIEW, [], ['X-Account-Id' => (string) $account->id])->assertForbidden();
        $this->assertDatabaseCount('erp_company_setup_states', 0);
    }

    public static function failurePoints(): array
    {
        return ['required record' => ['record'], 'receipt' => ['receipt']];
    }

    #[DataProvider('failurePoints')]
    public function test_required_failure_rolls_back_baseline_preferences_state_attribution_and_receipt(string $point): void
    {
        $ctx = $this->context(['time_zone' => null]);
        $preview = $this->preview();
        $class = $point === 'record' ? ErpContact::class : ErpOperationReceipt::class;
        $class::created(function (): void {
            abort(503, 'Injected required setup failure');
        });
        try {
            $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'failed'])->assertStatus(503);
        } finally {
            $class::flushEventListeners();
        }
        $this->assertSame(['time_zone' => null], $ctx['company']->fresh()->operating_settings);
        foreach (['erp_company_setup_states', 'erp_units', 'erp_contacts', 'erp_invoice_schemes', 'erp_invoice_layouts', 'erp_operation_receipts', 'erp_catalog_attributions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'failed'])->assertOk();
    }

    public function test_owner_revoked_during_required_write_cannot_commit_any_setup_effect(): void
    {
        $ctx = $this->context();
        $preview = $this->preview();
        ErpContact::created(function () use ($ctx): void {
            $ctx['membership']->update(['role' => 'manager']);
        });
        try {
            $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => 'revoked-during-write'])->assertForbidden();
        } finally {
            ErpContact::flushEventListeners();
        }
        foreach (['erp_company_setup_states', 'erp_units', 'erp_contacts', 'erp_invoice_schemes', 'erp_invoice_layouts', 'erp_operation_receipts', 'erp_catalog_attributions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], $ctx['company']->fresh()->operating_settings);
        $this->assertSame('owner', $ctx['membership']->fresh()->role);
    }

    public function test_reconciliation_does_not_adopt_numbering_or_reset_existing_sequence(): void
    {
        $ctx = $this->context();
        $numbers = app(ErpCommercialNumbers::class);
        $this->assertSame('S-000001', $numbers->allocate('sale', $ctx['company']->id, $ctx['establishment']->id, null));
        $sequence = DB::table('erp_commercial_number_sequences')->first();
        $preview = $this->preview();
        $body = $this->applyBody($preview);
        $this->postJson(self::APPLY, $body, ['Idempotency-Key' => 'historical-numbering'])->assertOk();
        $this->postJson(self::APPLY, $body, ['Idempotency-Key' => 'historical-numbering'])->assertOk();
        $this->assertEquals($sequence, DB::table('erp_commercial_number_sequences')->first());
        $this->assertFalse($ctx['company']->setupState()->firstOrFail()->sale_numbering_adopted);
        $this->assertSame('S-000002', $numbers->allocate('sale', $ctx['company']->id, $ctx['establishment']->id, null));
    }

    public function test_reconciliation_keeps_existing_subscription_and_operating_write_gates(): void
    {
        $ctx = $this->context();
        config(['erp.operating_writes_enabled' => false]);
        $this->postJson(self::PREVIEW)->assertForbidden()->assertJsonPath('code', 'erp_operating_disabled');
        config(['erp.operating_writes_enabled' => true]);
        ErpSubscription::query()->where('erp_company_id', $ctx['company']->id)->update(['status' => 'canceled']);
        $this->postJson(self::PREVIEW)->assertForbidden()->assertJsonPath('code', 'erp_subscription_inactive');
        $this->assertDatabaseCount('erp_company_setup_states', 0);
    }

    public function test_apply_requires_valid_bound_preview_and_operation_key_before_any_effect(): void
    {
        $this->context();
        $preview = $this->preview();
        $this->postJson(self::APPLY, $this->applyBody($preview))->assertUnprocessable();
        $this->postJson(self::APPLY, ['preview_version' => 'unknown', 'fingerprint' => $preview['fingerprint']], ['Idempotency-Key' => 'wrong-version'])->assertUnprocessable();
        $this->postJson(self::APPLY, ['preview_version' => $preview['preview_version'], 'fingerprint' => str_repeat('a', 64)], ['Idempotency-Key' => 'forged'])->assertConflict();
        $this->postJson(self::APPLY, $this->applyBody($preview), ['Idempotency-Key' => ' bad key'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_company_setup_states', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }
}
