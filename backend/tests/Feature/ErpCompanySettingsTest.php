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

    public function test_fiscal_default_codes_and_zero_rates_round_trip_as_exact_strings(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany();
        $this->login($user);

        $settings = [
            'cst_csosn_padrao' => '101',
            'cst_pis_padrao' => '01',
            'cst_cofins_padrao' => '01',
            'cst_ipi_padrao' => '00',
            'perc_icms_padrao' => '0.0000',
            'perc_pis_padrao' => '0',
            'perc_cofins_padrao' => '0.0000',
            'perc_ipi_padrao' => '0',
            'pCredSN' => '0.0000',
        ];

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', $settings)
            ->assertOk()
            ->assertJsonPath('data.cst_csosn_padrao', '101')
            ->assertJsonPath('data.cst_pis_padrao', '01')
            ->assertJsonPath('data.cst_ipi_padrao', '00')
            ->assertJsonPath('data.perc_icms_padrao', '0.0000')
            ->assertJsonPath('data.perc_pis_padrao', '0');

        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/company/settings')
            ->assertOk()
            ->assertJsonPath('data.cst_csosn_padrao', '101')
            ->assertJsonPath('data.cst_pis_padrao', '01')
            ->assertJsonPath('data.cst_ipi_padrao', '00')
            ->assertJsonPath('data.perc_icms_padrao', '0.0000')
            ->assertJsonPath('data.pCredSN', '0.0000');
    }

    public function test_invalid_fiscal_default_code_returns_named_validation_feedback(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany();
        $this->login($user);

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', ['cst_pis_padrao' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cst_pis_padrao']);
    }

    public function test_fiscal_default_rates_require_exact_decimal_strings(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany();
        $this->login($user);

        $this->withHeaders(['X-CSRF-Token' => $this->csrf(), 'X-Company-Id' => (string) $company->id])
            ->patchJson('/api/v1/erp/company/settings', ['perc_icms_padrao' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['perc_icms_padrao']);
    }

    public function test_unresolved_numeric_legacy_code_remains_visible_without_reclassification(): void
    {
        ['user' => $user, 'company' => $company] = $this->ownerWithCompany();
        $company->forceFill(['operating_settings' => ['cst_pis_padrao' => 1]])->save();
        $this->login($user);

        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/company/settings')
            ->assertOk()
            ->assertJsonPath('data.cst_pis_padrao', 1);
    }
}
