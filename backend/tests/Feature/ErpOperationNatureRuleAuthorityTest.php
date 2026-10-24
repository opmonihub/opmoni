<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOperationNatureHarness;
use Tests\TestCase;

class ErpOperationNatureRuleAuthorityTest extends TestCase
{
    use BuildsOperationNatureHarness, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureNatureHarness();
    }

    public function test_children_inherit_nature_establishment_visibility_and_mutations_recheck_current_scope(): void
    {
        [$company, , $membership, $establishment] = $this->natureOwner();
        $other = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $nature = $this->createNature(['erp_establishment_id' => $establishment->id]);
        $choice = $this->choice($nature['id'], '5101', 1);
        $product = $this->natureProduct($company);
        $exception = $this->exception($nature['id'], $product, $choice['id'], 2);
        $membership->update(['role' => 'operator', 'access_all_establishments' => false, 'action_permissions' => ['erp.operation-natures.index', 'erp.operation-natures.update', 'erp.sales.store']]);
        $membership->establishments()->sync([$establishment->id => ['erp_company_id' => $company->id]]);
        $this->getJson(self::NATURES.'/'.$nature['id'].'/choices/'.$choice['id'])->assertOk();
        $membership->establishments()->sync([$other->id => ['erp_company_id' => $company->id]]);
        $this->getJson(self::NATURES.'/'.$nature['id'].'/choices')->assertNotFound();
        $this->getJson(self::NATURES.'/'.$nature['id'].'/product-exceptions/'.$exception['id'])->assertNotFound();
        $this->patchJson(self::NATURES.'/'.$nature['id'].'/product-exceptions/'.$exception['id'], ['erp_operation_nature_cfop_choice_id' => $choice['id'], 'expected_revision' => 3])->assertNotFound();
        $this->postJson(self::NATURES.'/operational-preview', $this->previewPayload($product, $nature['id'], $establishment))->assertUnprocessable();
        $this->assertDatabaseHas('erp_operation_natures', ['id' => $nature['id'], 'configuration_revision' => 3]);
    }

    public function test_preview_rejects_foreign_and_incompatible_relationships_without_revealing_direct_foreign_resources(): void
    {
        [$company, , , $establishment] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature(['erp_establishment_id' => $establishment->id]);
        $foreign = ErpOperationNature::factory()->create();
        $other = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $payload = $this->previewPayload($product, $nature['id'], $establishment);
        $this->postJson(self::NATURES.'/operational-preview', [...$payload, 'erp_operation_nature_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('erp_operation_nature_id');
        $this->postJson(self::NATURES.'/operational-preview', [...$payload, 'erp_product_id' => $this->natureProduct($foreign->company)->id])->assertUnprocessable()->assertJsonValidationErrors('erp_product_id');
        $this->postJson(self::NATURES.'/operational-preview', [...$payload, 'context' => $this->operationContext($other)])->assertUnprocessable()->assertJsonValidationErrors('erp_operation_nature_id');
        $foreignEstablishment = ErpEstablishment::factory()->create();
        $this->postJson(self::NATURES.'/operational-preview', [...$payload, 'context' => $this->operationContext($establishment, ['erp_establishment_id' => $foreignEstablishment->id])])->assertUnprocessable()->assertJsonValidationErrors('context.erp_establishment_id');
        $this->postJson(self::NATURES.'/operational-preview', [...$payload, 'erp_operation_nature_id' => null, 'context' => $this->operationContext($establishment, ['erp_establishment_id' => $foreignEstablishment->id])])->assertUnprocessable()->assertJsonValidationErrors('context.erp_establishment_id');
        $this->getJson(self::NATURES.'/'.$foreign->id.'/product-exceptions')->assertNotFound();
    }

    public function test_delegated_exact_nature_update_authorizes_children_but_sales_write_does_not_and_revocation_preserves_history(): void
    {
        $company = ErpCompany::factory()->create();
        ErpSubscription::factory()->active()->create(['erp_company_id' => $company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id]);
        $actor = User::factory()->create();
        $office = Account::factory()->create();
        $officeMembership = AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $actor->id, 'role' => 'operador']);
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id, 'permission_allowlist' => ['erp.read', 'erp.operation-natures.index', 'erp.operation-natures.update']]);
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id]);
        $this->natureLogin($actor, $company);
        $this->withHeader('X-Account-Id', (string) $office->id);
        $choice = $this->choice($nature->id, '5101', 1);
        $this->getJson(self::NATURES.'/'.$nature->id.'/choices/'.$choice['id'])->assertOk();
        $audit = DB::table('erp_catalog_attributions')->where('resource_type', 'operation_nature_cfop_choice')->where('resource_id', $choice['id'])->sole();
        $this->assertSame('delegated', $audit->access_kind);
        $this->assertSame($delegation->id, $audit->erp_office_delegation_id);
        $this->assertSame($office->id, $audit->account_id);
        $this->assertNull($audit->erp_company_membership_id);
        $delegation->update(['permission_allowlist' => ['erp.read', 'erp.sales.store']]);
        $this->postJson(self::NATURES.'/'.$nature->id.'/choices', ['cfop' => '5102', 'expected_revision' => 2])->assertForbidden();
        $delegation->update(['permission_allowlist' => ['erp.read', 'erp.operation-natures.update']]);
        $officeMembership->update(['role' => 'user']);
        $this->postJson(self::NATURES.'/'.$nature->id.'/choices', ['cfop' => '5102', 'expected_revision' => 2])->assertForbidden();
        $officeMembership->update(['role' => 'operador']);
        $delegation->update(['status' => 'revoked']);
        $this->postJson(self::NATURES.'/'.$nature->id.'/choices', ['cfop' => '5102', 'expected_revision' => 2])->assertForbidden();
        $this->assertDatabaseHas('erp_operation_natures', ['id' => $nature->id, 'configuration_revision' => 2]);
    }
}
