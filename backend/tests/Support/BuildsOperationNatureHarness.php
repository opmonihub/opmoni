<?php

namespace Tests\Support;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;

trait BuildsOperationNatureHarness
{
    private const NATURES = '/api/v1/erp/tax-settings/operation-natures';

    private function configureNatureHarness(): void
    {
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    /** @return array{ErpCompany, User, ErpCompanyMembership, ErpEstablishment} */
    private function natureOwner(): array
    {
        $company = ErpCompany::factory()->create(['operating_settings' => ['regime' => '3']]);
        ErpSubscription::factory()->active()->create(['erp_company_id' => $company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id]);
        $user = User::factory()->create();
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $this->natureLogin($user, $company);

        return [$company, $user, $membership, $establishment];
    }

    private function natureLogin(User $user, ErpCompany $company): void
    {
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
    }

    private function natureProduct(ErpCompany $company, array $extra = []): ErpProduct
    {
        return ErpProduct::factory()->create(['erp_company_id' => $company->id, 'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $company->id])->id, ...$extra]);
    }

    private function createNature(array $extra = []): array
    {
        return $this->postJson(self::NATURES, ['cfop' => '5102', 'description' => 'Venda de mercadoria', 'movement' => 'out', ...$extra])->assertCreated()->json('data');
    }

    private function choice(int $natureId, string $code, int $revision): array
    {
        return $this->postJson(self::NATURES.'/'.$natureId.'/choices', ['cfop' => $code, 'expected_revision' => $revision])->assertCreated()->json('data');
    }

    private function exception(int $natureId, ErpProduct $product, int $choiceId, int $revision): array
    {
        return $this->postJson(self::NATURES.'/'.$natureId.'/product-exceptions', ['erp_product_id' => $product->id, 'erp_operation_nature_cfop_choice_id' => $choiceId, 'expected_revision' => $revision])->assertCreated()->json('data');
    }

    private function operationContext(ErpEstablishment $establishment, array $extra = []): array
    {
        return ['erp_establishment_id' => $establishment->id, 'movement' => 'out', 'origin_uf' => 'SP', 'destination_uf' => 'SP', 'model' => '55', 'purpose' => 'normal', ...$extra];
    }

    private function previewPayload(ErpProduct $product, int $natureId, ErpEstablishment $establishment, array $extra = []): array
    {
        return ['source_action' => 'erp.sales.store', 'erp_product_id' => $product->id, 'erp_operation_nature_id' => $natureId, 'context' => $this->operationContext($establishment), ...$extra];
    }
}
