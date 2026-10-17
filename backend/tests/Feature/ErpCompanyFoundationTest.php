<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ErpCompanyFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function login(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_company_only_identity_and_default_selection(): void
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);

        $this->login($user);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('current_account', null)
            ->assertJsonPath('current_company.id', $company->id)
            ->assertJsonCount(1, 'companies');
    }

    public function test_company_switch_and_context_assertion(): void
    {
        $user = User::factory()->create();
        $first = ErpCompany::factory()->create(['name' => 'Alpha', 'cnpj' => '11222333000181']);
        $second = ErpCompany::factory()->create(['name' => 'Beta', 'cnpj' => self::VALID_CNPJ]);
        foreach ([$first, $second] as $company) {
            ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        }

        $this->login($user);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('current_company.id', $second->id);

        $this->withHeader('X-Company-Id', (string) $second->id)
            ->getJson('/api/v1/erp/establishments')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $this->withHeader('X-Company-Id', (string) $first->id)
            ->getJson('/api/v1/erp/establishments')
            ->assertStatus(409)
            ->assertJsonPath('code', 'company_context_changed');

        $this->withoutHeader('X-Company-Id')
            ->getJson('/api/v1/erp/establishments')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_error');
    }

    public function test_office_switch_invalidates_delegated_company(): void
    {
        $user = User::factory()->create();
        $officeA = Account::factory()->create();
        $officeB = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $officeA->id, 'user_id' => $user->id, 'role' => 'admin']);
        AccountMembership::factory()->create(['account_id' => $officeB->id, 'user_id' => $user->id, 'role' => 'admin']);

        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $officeA->id,
            'authorized_by_user_id' => $owner->id,
        ]);

        $this->login($user);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('current_company.access_kind', 'delegated');

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/account/switch', ['account_id' => $officeB->id])
            ->assertOk();

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('current_company', null);
    }

    public function test_create_company_and_duplicate_membership_guard(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $this->login($user);

        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/companies', [
            'name' => 'Nova Empresa',
            'cnpj' => self::VALID_CNPJ,
        ])->assertCreated()->assertJsonPath('current_company.name', 'Nova Empresa');

        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/companies', [
            'name' => 'Outra',
            'cnpj' => self::VALID_CNPJ,
        ])->assertStatus(422);
    }

    public function test_super_admin_without_company_membership_is_denied(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);

        $this->login($admin);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertForbidden();
    }

    public function test_delegation_replacement_and_revocation(): void
    {
        $owner = User::factory()->create();
        $officeA = Account::factory()->create();
        $officeB = Account::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->login($owner);
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/delegations', [
            'company_id' => $company->id,
            'account_id' => $officeA->id,
            'permissions' => ['erp.read'],
        ])->assertCreated();

        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/delegations', [
            'company_id' => $company->id,
            'account_id' => $officeB->id,
            'permissions' => ['erp.read'],
        ])->assertCreated();

        $this->assertSame(1, ErpOfficeDelegation::query()->where('erp_company_id', $company->id)->where('status', 'active')->count());

        $delegation = ErpOfficeDelegation::query()->where('account_id', $officeB->id)->firstOrFail();
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->deleteJson('/api/v1/erp/delegations/'.$delegation->id)
            ->assertOk();

        $this->assertSame(0, ErpOfficeDelegation::query()->where('erp_company_id', $company->id)->where('status', 'active')->count());
    }
}
