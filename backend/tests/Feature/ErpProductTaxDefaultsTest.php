<?php

namespace Tests\Feature;

use App\Erp\ErpCompanyProductTaxDefaults;
use App\Erp\ErpProductTaxResolver;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ErpProductTaxDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{CurrentCompany, ErpProduct} */
    private function context(array $settings = []): array
    {
        $company = ErpCompany::factory()->create(['operating_settings' => $settings]);
        $user = User::factory()->create();
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $context = new CurrentCompany($company, 'direct', $membership, null, null);
        $product = ErpProduct::factory()->create(['erp_company_id' => $company->id, 'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $company->id])->id]);

        return [$context, $product];
    }

    private function profile(CurrentCompany $context, array $fields, array $applicability = []): ErpProductTaxProfile
    {
        $profile = ErpProductTaxProfile::query()->create(['erp_company_id' => $context->company->id, 'name' => 'Reviewed', 'status' => 'active']);
        $profile->revisions()->create([
            'erp_company_id' => $context->company->id, 'revision' => 1, 'name' => 'Reviewed', 'status' => 'active',
            'fields' => $fields, 'applicability' => $applicability, 'actor_user_id' => $context->membership->user_id,
            'access_kind' => 'direct', 'erp_company_membership_id' => $context->membership->id,
        ]);
        $profile->update(['current_revision' => 1]);

        return $profile;
    }

    public function test_company_default_is_explicit_and_legacy_products_remain_unresolved_until_adoption(): void
    {
        [$context, $product] = $this->context(['regime' => '3']);
        $profile = $this->profile($context, ['pis_cst' => '01']);
        DB::table('erp_company_product_tax_defaults')->insert(['erp_company_id' => $context->company->id, 'erp_product_tax_profile_id' => $profile->id, 'revision' => 7]);
        $resolver = app(ErpProductTaxResolver::class);
        $this->assertNull($resolver->resolve($context, $product)['values']['pis_cst']);
        $product->tax_field_intents = ['pis_cst' => 'inherit'];
        $result = $resolver->resolve($context, $product);
        $this->assertSame('01', $result['values']['pis_cst']);
        $this->assertSame('company_profile', $result['sources']['pis_cst']['source']);
        $this->assertSame(7, $result['company_default']['revision']);
        $this->assertNull($product->fresh()->tax_field_intents);
    }

    public function test_profile_absence_falls_back_but_profile_explicit_null_stops_fallback(): void
    {
        [$context, $product] = $this->context(['regime' => '3']);
        $selected = $this->profile($context, ['pis_cst' => '01', 'pis_rate' => null]);
        $default = $this->profile($context, ['pis_rate' => '9.0000', 'cofins_cst' => '02']);
        DB::table('erp_company_product_tax_defaults')->insert(['erp_company_id' => $context->company->id, 'erp_product_tax_profile_id' => $default->id, 'revision' => 1]);
        $product->erp_product_tax_profile_id = $selected->id;
        $product->tax_field_intents = ['pis_cst' => 'inherit', 'pis_rate' => 'inherit', 'cofins_cst' => 'inherit'];
        $result = app(ErpProductTaxResolver::class)->resolve($context, $product);
        $this->assertSame('01', $result['values']['pis_cst']);
        $this->assertNull($result['values']['pis_rate']);
        $this->assertSame('profile', $result['sources']['pis_rate']['source']);
        $this->assertSame('02', $result['values']['cofins_cst']);
        $this->assertSame('company_profile', $result['sources']['cofins_cst']['source']);
        $this->assertContains('pis_rate', $result['unresolved_fields']);
    }

    public function test_only_stored_valid_compatible_company_fields_participate_without_guessed_combined_conversion(): void
    {
        [$context, $product] = $this->context(['regime' => '3', 'cst_csosn_padrao' => 0, 'cst_pis_padrao' => '01', 'perc_pis_padrao' => '0.0000']);
        $product->tax_field_intents = ['icms_cst' => 'inherit', 'pis_cst' => 'inherit', 'pis_rate' => 'inherit', 'cofins_cst' => 'inherit'];
        $result = app(ErpProductTaxResolver::class)->resolve($context, $product);
        $this->assertNull($result['values']['icms_cst']);
        $this->assertSame('01', $result['values']['pis_cst']);
        $this->assertSame('0.0000', $result['values']['pis_rate']);
        $this->assertNull($result['values']['cofins_cst']);
        $this->assertSame('company_setting', $result['sources']['pis_cst']['source']);
        $context->company->operating_settings = ['cst_csosn_padrao' => '00'];
        $context->company->save();
        $this->assertNull(app(ErpProductTaxResolver::class)->resolve($context, $product)['values']['icms_cst']);
        $context->company->operating_settings = ['regime' => '3', 'cst_csosn_padrao' => '00'];
        $context->company->save();
        $this->assertSame('00', app(ErpProductTaxResolver::class)->resolve($context, $product)['values']['icms_cst']);
    }

    public function test_missing_applicability_context_is_explicit_and_invalid_default_never_uses_another_source(): void
    {
        [$context, $product] = $this->context(['regime' => '3', 'cst_pis_padrao' => '02']);
        $profile = $this->profile($context, ['pis_cst' => '01'], ['tax_regime' => '3', 'movement' => 'out']);
        DB::table('erp_company_product_tax_defaults')->insert(['erp_company_id' => $context->company->id, 'erp_product_tax_profile_id' => $profile->id, 'revision' => 1]);
        $product->tax_field_intents = ['pis_cst' => 'inherit'];
        $this->expectException(ValidationException::class);
        app(ErpProductTaxResolver::class)->resolve($context, $product);
    }

    public function test_default_service_has_no_virtual_confirmed_profile_or_unprotected_mutation(): void
    {
        [$context] = $this->context();
        $service = app(ErpCompanyProductTaxDefaults::class);
        $this->assertSame(['default_product_tax_profile_id' => null, 'tax_default_revision' => 0], $service->toPayload($context->company));
        $this->assertDatabaseCount('erp_company_product_tax_defaults', 0);
        $request = Request::create('/api/v1/erp/company/settings', 'PATCH');
        $this->expectException(\LogicException::class);
        $service->update($request, $context, ['default_product_tax_profile_id' => null]);
    }
}
