<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.registration_enabled' => true,
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
        User::factory()->create();
        Account::factory()->create();
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    public function test_registration_status_respects_gate_and_platform(): void
    {
        config(['erp.registration_enabled' => false]);
        $this->getJson('/api/v1/erp/registration-status')
            ->assertOk()
            ->assertJsonPath('platform_ready', true)
            ->assertJsonPath('registration_available', false);
    }

    public function test_new_owner_registration_creates_pending_subscription_without_office_account(): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/register', [
                'name' => 'ERP Owner',
                'email' => 'erp-new@example.test',
                'password' => 'password123',
                'company_name' => 'Nova Empresa',
                'cnpj' => self::VALID_CNPJ,
            ])
            ->assertCreated()
            ->assertJsonPath('current_company.name', 'Nova Empresa');

        $this->assertSame(2, User::query()->count());
        $this->assertSame(1, Account::query()->count());
        $company = ErpCompany::query()->where('cnpj', self::VALID_CNPJ)->firstOrFail();
        $this->assertSame('pending', $company->subscription->status);
        $this->assertNotEmpty($company->subscription->plan->modules);
    }

    public function test_existing_email_requires_login_without_mutation(): void
    {
        $existing = User::factory()->create(['email' => 'exists@example.test']);
        $hash = $existing->password;

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/register', [
                'name' => 'Other',
                'email' => 'exists@example.test',
                'password' => 'password123',
                'company_name' => 'Other Co',
                'cnpj' => '11222333000181',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'login_required');

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame(0, ErpCompany::query()->where('cnpj', '11222333000181')->count());
    }

    public function test_uninitialized_platform_rejects_public_registration(): void
    {
        DB::table('account_memberships')->delete();
        DB::table('accounts')->delete();
        DB::table('users')->delete();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/register', [
                'name' => 'Early',
                'email' => 'early@example.test',
                'password' => 'password123',
                'company_name' => 'Early Co',
                'cnpj' => self::VALID_CNPJ,
            ])
            ->assertForbidden();
    }

    public function test_authenticated_user_creates_company_with_pending_subscription(): void
    {
        $user = User::factory()->create();
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/companies', [
                'name' => 'Second Co',
                'cnpj' => self::VALID_CNPJ,
            ])
            ->assertCreated()
            ->assertJsonPath('current_company.name', 'Second Co');

        $companyId = ErpCompany::query()->where('cnpj', self::VALID_CNPJ)->value('id');
        $this->assertSame('pending', ErpSubscription::query()->where('erp_company_id', $companyId)->value('status'));
    }

    public function test_catalog_initialization_is_insert_only(): void
    {
        $catalog = app(ErpCommercialCatalog::class);
        $first = $catalog->ensureBasicPlan();
        $first->update(['name' => 'Custom ERP Plan']);
        $second = $catalog->ensureBasicPlan();
        $this->assertSame('Custom ERP Plan', $second->fresh()->name);
        $this->assertSame(1, ErpPlan::query()->count());
    }

    public function test_provisioning_rollback_leaves_no_partial_contract_on_duplicate_cnpj(): void
    {
        ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        $user = User::factory()->create();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/companies', ['name' => 'Dup', 'cnpj' => self::VALID_CNPJ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cnpj']);

        $this->assertSame(1, ErpCompany::query()->where('cnpj', self::VALID_CNPJ)->count());
        $this->assertSame(0, ErpCompany::query()->where('name', 'Dup')->count());
    }
}
