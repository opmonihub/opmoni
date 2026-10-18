<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use App\Support\ErpCompanyContext;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpDelegatedOrdinaryAccessTest extends TestCase
{
    use RefreshDatabase;

    private ErpCompany $company;

    private User $actor;

    private Account $office;

    private AccountMembership $officeMembership;

    private ErpOfficeDelegation $delegation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $this->actor = User::factory()->create();
        $this->office = Account::factory()->create();
        $this->officeMembership = AccountMembership::factory()->create(['account_id' => $this->office->id, 'user_id' => $this->actor->id, 'role' => 'admin']);
        $this->company = ErpCompany::factory()->create();
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $this->company->id, 'account_id' => $this->office->id, 'permission_allowlist' => ['erp.read', 'erp.brands.store']]);
    }

    private function loginAndSelect(): void
    {
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $this->actor->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'X-Company-Id' => (string) $this->company->id]);
    }

    #[DataProvider('officeRoles')]
    public function test_office_role_intersects_actual_reads_writes_and_identity(string $role, bool $canWrite): void
    {
        $this->officeMembership->update(['role' => $role]);
        $this->loginAndSelect();
        $this->getJson('/api/v1/erp/brands')->assertOk();
        $permissions = $this->getJson('/api/v1/me')->assertOk()->json('current_company.permissions');
        $this->assertContains('erp.read', $permissions);
        $this->assertContains('erp.brands.index', $permissions);
        $this->assertSame($canWrite, in_array('erp.brands.store', $permissions, true));
        $response = $this->postJson('/api/v1/erp/brands', ['name' => 'Authorized brand']);
        $canWrite ? $response->assertCreated() : $response->assertForbidden();
        $this->assertDatabaseCount('erp_brands', $canWrite ? 1 : 0);
        $this->postJson('/api/v1/erp/categories', ['name' => 'Outside grant'])->assertForbidden();
    }

    public static function officeRoles(): array
    {
        return [['user', false], ['admin', true], ['operador', true]];
    }

    public function test_narrow_read_grant_stays_narrow_in_both_identity_projections(): void
    {
        $this->officeMembership->update(['role' => 'user']);
        $this->delegation->update(['permission_allowlist' => ['erp.brands.index']]);
        $this->loginAndSelect();
        $identity = $this->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['erp.brands.index'], $identity->json('current_company.permissions'));
        $this->assertSame(['erp.brands.index'], $identity->json('companies.0.permissions'));
        $this->getJson('/api/v1/erp/brands')->assertOk();
        $this->getJson('/api/v1/erp/categories')->assertForbidden();
        $this->postJson('/api/v1/erp/brands', ['name' => 'Denied'])->assertForbidden();
    }

    public function test_wildcards_never_allow_or_advertise_reserved_authority(): void
    {
        $this->delegation->update(['permission_allowlist' => ['*']]);
        $this->loginAndSelect();
        $permissions = $this->getJson('/api/v1/me')->assertOk()->json('current_company.permissions');
        foreach (['*', 'erp.members.index', 'erp.accounting-mappings.index', 'erp.accounting-deliveries.store', 'erp.fiscal.certificates.store'] as $reserved) {
            $this->assertNotContains($reserved, $permissions);
        }
        $this->getJson('/api/v1/erp/members')->assertForbidden();
        $this->getJson('/api/v1/erp/accounting-mappings')->assertForbidden();
        $this->postJson('/api/v1/erp/accounting-deliveries', ['erp_fiscal_document_id' => 1])->assertForbidden();
        $this->postJson('/api/v1/erp/brands', ['name' => 'Ordinary wildcard'])->assertCreated();
    }

    public function test_default_allowlist_read_only_and_direct_viewer_precedence(): void
    {
        $this->delegation->update(['permission_allowlist' => []]);
        $this->loginAndSelect();
        $this->getJson('/api/v1/erp/brands')->assertOk();
        $this->postJson('/api/v1/erp/brands', ['name' => 'Denied'])->assertForbidden();
        $this->delegation->update(['permission_allowlist' => ['*']]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->actor->id, 'role' => 'viewer', 'action_permissions' => ['*']]);
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk()->assertJsonPath('current_company.access_kind', 'direct');
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/erp/brands', ['name' => 'Still denied'])->assertForbidden();
        $this->assertDatabaseCount('erp_brands', 0);
    }

    public function test_current_role_office_and_exact_delegation_are_rechecked(): void
    {
        $this->loginAndSelect();
        $this->officeMembership->update(['role' => 'user']);
        $this->postJson('/api/v1/erp/brands', ['name' => 'Demoted'])->assertForbidden();
        $this->officeMembership->update(['role' => 'admin']);
        $this->office->update(['status' => 'suspended']);
        $this->getJson('/api/v1/erp/brands')->assertForbidden();
        $this->office->update(['status' => 'active']);
        $this->delegation->update(['status' => 'revoked']);
        ErpOfficeDelegation::factory()->create(['erp_company_id' => $this->company->id, 'account_id' => $this->office->id, 'permission_allowlist' => ['*']]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('current_company', null);
        $this->postJson('/api/v1/erp/brands', ['name' => 'Replaced'])->assertForbidden();
        $this->assertDatabaseCount('erp_brands', 0);
    }

    public function test_session_loss_after_effect_rolls_back_and_restores_context(): void
    {
        $this->loginAndSelect();
        ErpBrand::created(function (): void {
            request()->attributes->get('access.session_lock')->forceRelease();
        });
        $this->postJson('/api/v1/erp/brands', ['name' => 'Lost lease'])->assertStatus(503);
        $this->assertDatabaseCount('erp_brands', 0);
        $this->assertNull(app(ErpCompanyContext::class)->companyId);
        $this->assertNull(app(CurrentTenant::class)->accountId);
    }

    public function test_receipt_lookup_uses_write_authority_even_though_http_get_is_safe(): void
    {
        $this->delegation->update(['permission_allowlist' => ['erp.read', 'erp.obligations.store', 'erp.payments.store', 'erp.payments.allocate']]);
        $this->loginAndSelect();
        $obligation = $this->postJson('/api/v1/erp/obligations', ['direction' => 'receivable', 'category' => 'manual', 'amount' => '100'])->assertCreated()->json('data.id');
        $payment = $this->postJson('/api/v1/erp/payments', ['amount' => '100', 'method' => 'cash'])->assertCreated()->json('data.id');
        $original = $this->postJson('/api/v1/erp/payments/'.$payment.'/allocate', ['erp_obligation_id' => $obligation, 'amount' => '40'], ['Idempotency-Key' => 'delegated-allocation'])->assertOk()->json();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate', ['Idempotency-Key' => 'delegated-allocation'])->assertOk()->assertJsonPath('data.result', $original['data']);
        $this->officeMembership->update(['role' => 'user']);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate', ['Idempotency-Key' => 'delegated-allocation'])->assertForbidden();
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
    }

    public function test_new_delegations_reject_unknown_reserved_and_wildcard_grants(): void
    {
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->actor->id, 'role' => 'owner']);
        $this->loginAndSelect();
        foreach (['*', 'erp.write', 'erp.members.store', 'erp.accounting-deliveries.store', 'erp.fiscal.certificates.store', 'unknown.permission'] as $permission) {
            $this->postJson('/api/v1/erp/delegations', ['company_id' => $this->company->id, 'account_id' => $this->office->id, 'permissions' => [$permission]])->assertUnprocessable();
        }
        $this->assertSame('active', $this->delegation->fresh()->status);
        $this->assertDatabaseCount('erp_office_delegations', 1);
    }
}
