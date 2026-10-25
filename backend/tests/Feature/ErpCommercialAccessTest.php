<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpCommercialGate;
use App\Models\Account;
use App\Models\Erp\ErpCommercialAuditLog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpCommercialAccessTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
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
        $this->seed(PlanSeeder::class);
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    /** @return array{user: User, company: ErpCompany} */
    private function ownerWithCompany(string $status = 'pending'): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => $status,
        ]);

        return ['user' => $user, 'company' => $company->fresh(['subscription.plan.modules'])];
    }

    private function login(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_inactive_subscription_blocks_operating_writes_but_allows_contract_read(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany('pending');
        $this->login($user);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', ['tax_number_1' => '1234567890'])
            ->assertOk();

        config(['erp.operating_writes_enabled' => true]);
        // No mutating ERP route besides settings in foundation; gate still applies when added — verify via establishments POST would 403 if existed.
        $this->assertFalse(app(ErpCommercialGate::class)->allowsOperatingWrites($company));
    }

    public function test_canceled_subscription_blocks_a_real_company_write_without_business_effects(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany('canceled');
        $this->login($user);

        $response = $this->withHeaders([
            'X-CSRF-Token' => $this->csrf(),
            'X-Company-Id' => (string) $company->id,
        ])
            ->postJson('/api/v1/erp/establishments', ['name' => 'Unidade bloqueada', 'status' => 'active'])
            ->assertForbidden();
        $this->assertSame('erp_subscription_inactive', $response->json('code'), $response->getContent());

        $this->assertSame(0, ErpEstablishment::query()
            ->where('erp_company_id', $company->id)->count());
        $this->assertSame('canceled', $company->subscription()->firstOrFail()->fresh()->status);
    }

    public function test_active_subscription_allows_operating_writes_when_flag_enabled(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany('active');
        $this->login($user);
        $this->assertTrue(app(ErpCommercialGate::class)->allowsOperatingWrites($company));
    }

    public function test_office_subscription_changes_do_not_rewrite_company_contract(): void
    {
        ['company' => $company] = $this->ownerWithCompany('active');
        $account = Account::factory()->create();
        $account->subscription()->update(['status' => 'canceled']);

        $subscription = $company->subscription()->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame(1, ErpSubscription::query()->where('erp_company_id', $company->id)->count());
    }

    public function test_super_admin_manages_erp_plans_and_audits_subscription_updates(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ['company' => $company] = $this->ownerWithCompany('pending');

        $this->login($admin);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->patchJson('/api/v1/admin/erp/subscriptions/'.$company->subscription->id, ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, ErpCommercialAuditLog::query()->where('action', 'erp_subscription.update')->count());
    }

    public function test_owner_cannot_activate_subscription_via_platform_api(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany('pending');
        $subscription = $company->subscription()->firstOrFail();
        $this->login($user);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->patchJson('/api/v1/admin/erp/subscriptions/'.$subscription->id, ['status' => 'active'])
            ->assertForbidden();

        $this->assertSame('pending', $subscription->fresh()->status);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_office_plans_table_unaffected_by_erp_catalog(): void
    {
        app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $this->assertSame(3, Plan::query()->count());
        $this->assertSame(1, ErpPlan::query()->count());
    }
}
