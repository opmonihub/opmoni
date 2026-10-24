<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpCfopReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
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

    public function test_authorized_reference_lookup_returns_verified_facts_and_supported_context_metadata(): void
    {
        $context = $this->ownerContext();

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?q=5102&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.code', '5102')
            ->assertJsonPath('data.0.description', 'Venda de mercadoria adquirida ou recebida de terceiros, ou qualquer venda de mercadoria efetuada pelo MEI com exceção das saídas classificadas nos códigos 5.501, 5.502, 5.504 e 5.505.')
            ->assertJsonPath('data.0.reference_version', 'IT 2023.002 v2.10')
            ->assertJsonPath('data.0.source_metadata.indNFe', '1')
            ->assertJsonPath('data.0.applicability.direction', 'outbound')
            ->assertJsonPath('data.0.applicability.status', 'requires_context')
            ->assertJsonMissingPath('data.0.tax_treatment')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_selected_reference_hydration_is_not_limited_to_the_search_page(): void
    {
        $context = $this->ownerContext();

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops/5102')
            ->assertOk()
            ->assertJsonPath('data.code', '5102')
            ->assertJsonPath('data.reference_version', 'IT 2023.002 v2.10')
            ->assertJsonPath('data.source_metadata.indNFe', '1')
            ->assertJsonMissingPath('data.tax_treatment');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops/1651')
            ->assertOk()
            ->assertJsonPath('data.source_metadata.indComb', '2');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops/7102')
            ->assertOk()
            ->assertJsonPath('data.code', '7102')
            ->assertJsonPath('data.applicability.status', 'unsupported')
            ->assertJsonPath('data.applicability.reason', 'export_not_supported');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops/9999')
            ->assertNotFound();
    }

    public function test_reference_version_is_pinned_to_the_verified_complete_source_dataset(): void
    {
        $context = $this->ownerContext();

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 619)
            ->assertJsonPath('meta.reference_version', 'IT 2023.002 v2.10')
            ->assertJsonPath('meta.source_sha256', '577e05eec452294945d0e9df1f9bb9b21a4af115938e75ec74cf6a541ae4dacf');
    }

    public function test_unavailable_reference_artifact_returns_recoverable_error_instead_of_empty_results(): void
    {
        $context = $this->ownerContext();
        config(['erp.cfop_reference_path' => base_path('missing-cfop-reference.json')]);

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?q=5102')
            ->assertStatus(503)
            ->assertJsonPath('code', 'cfop_reference_unavailable');
    }

    public function test_reference_search_is_bounded_and_filters_supported_context_without_inventing_eligibility(): void
    {
        $context = $this->ownerContext();

        $response = $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?direction=outbound&per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonMissingPath('data.0.tax_treatment');
        foreach ($response->json('data') as $reference) {
            $this->assertMatchesRegularExpression('/^[567]\d{3}$/', $reference['code']);
        }

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?direction=outbound&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 2);

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?direction=unsupported-context')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?model=65&q=6102')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?model=65&q=5102')
            ->assertOk()
            ->assertJsonPath('data.0.applicability.nfce_reference_listed', true)
            ->assertJsonPath('data.0.applicability.status', 'requires_context');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?model=65&q=5949')
            ->assertOk()
            ->assertJsonPath('data.0.code', '5949')
            ->assertJsonPath('data.0.applicability.nfce_reference_listed', false)
            ->assertJsonPath('data.0.applicability.nfce_conditional_uf_exception', true)
            ->assertJsonPath('data.0.applicability.status', 'requires_context');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops?model=57')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('model');
    }

    public function test_lookup_uses_operation_nature_read_authority_and_company_context(): void
    {
        $context = $this->ownerContext();
        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops')
            ->assertOk();

        $otherCompany = ErpCompany::factory()->create([
            'cnpj' => (string) random_int(90000000000000, 99999999999999),
        ]);
        $this->withHeaders(['X-Company-Id' => (string) $otherCompany->id])
            ->getJson('/api/v1/erp/tax-settings/cfops')
            ->assertStatus(409);
    }

    public function test_granular_operation_nature_index_permission_can_read_reference_hydration(): void
    {
        $context = $this->ownerContext();
        ErpCompanyMembership::query()
            ->where('erp_company_id', $context['company']->id)
            ->where('user_id', $context['user']->id)
            ->update(['role' => 'viewer', 'action_permissions' => ['erp.operation-natures.index']]);

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/tax-settings/cfops/5102')
            ->assertOk()
            ->assertJsonPath('data.code', '5102');
    }

    public function test_delegated_operation_nature_index_authority_can_search_and_hydrate_references(): void
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $office = Account::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $office->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
        $company = ErpCompany::factory()->create([
            'cnpj' => (string) random_int(90000000000000, 99999999999999),
        ]);
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
        ]);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
            'permission_allowlist' => ['erp.read', 'erp.operation-natures.index'],
        ]);

        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk();

        $headers = ['X-Company-Id' => (string) $company->id, 'X-Account-Id' => (string) $office->id];
        $this->withHeaders($headers)
            ->getJson('/api/v1/erp/tax-settings/cfops?q=5102')
            ->assertOk()
            ->assertJsonPath('data.0.code', '5102');
        $this->withHeaders($headers)
            ->getJson('/api/v1/erp/tax-settings/cfops/5102')
            ->assertOk()
            ->assertJsonPath('data.code', '5102');
    }

    /** @return array{user: User, company: ErpCompany, headers: array<string, string>} */
    private function ownerContext(): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create([
            'cnpj' => (string) random_int(90000000000000, 99999999999999),
        ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
        ]);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-Id' => (string) $company->id],
        ];
    }
}
