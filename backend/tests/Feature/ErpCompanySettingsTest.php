<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpCompanySettingsTest extends TestCase
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

    /** @return array{user: User, company: ErpCompany} */
    private function ownerWithCompany(): array
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);

        return ['user' => $user, 'company' => $company];
    }

    public function test_owner_can_read_and_update_company_settings(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany();
        $this->login($user);

        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/company/settings')
            ->assertOk()
            ->assertJsonPath('data.name', $company->name)
            ->assertJsonStructure(['data' => ['time_zone', 'accounting_method'], 'meta' => ['date_formats', 'months']]);

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', [
                'razao_social' => 'Empresa Demonstração LTDA',
                'ie' => '1234567890',
                'time_zone' => 'America/Sao_Paulo',
                'transaction_edit_days' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('data.razao_social', 'Empresa Demonstração LTDA')
            ->assertJsonPath('data.transaction_edit_days', 45);
    }

    public function test_razao_social_validation_matches_source(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany();
        $this->login($user);

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', ['razao_social' => 'curta'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['razao_social']);
    }

    public function test_foundation_settings_patch_allowed_when_operating_writes_disabled(): void
    {
        config(['erp.operating_writes_enabled' => false]);
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany();
        $this->login($user);

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', [
                'tax_label_1' => 'ISS',
                'enable_inline_tax' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.tax_label_1', 'ISS')
            ->assertJsonPath('data.enable_inline_tax', 1);
    }

    public function test_manager_can_patch_settings_when_operating_writes_disabled(): void
    {
        config(['erp.operating_writes_enabled' => false]);
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'manager']);
        ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $this->login($user);

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', ['tax_number_1' => '123'])
            ->assertOk()
            ->assertJsonPath('data.tax_number_1', '123');
    }

    public function test_viewer_cannot_patch_settings(): void
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'viewer']);
        ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $this->login($user);

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', ['tax_number_1' => '123'])
            ->assertForbidden();
    }
}
