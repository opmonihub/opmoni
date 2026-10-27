<?php

namespace Tests\Feature;

use App\Erp\ErpCfopResolver;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProductTaxProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsOperationNatureHarness;
use Tests\TestCase;

class ErpOperationNatureResolutionTest extends TestCase
{
    use BuildsOperationNatureHarness, RefreshDatabase;

    private const PREVIEW = '/api/v1/erp/tax-settings/operation-natures/operational-preview';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureNatureHarness();
    }

    public function test_explicit_then_exception_then_default_precedence_has_separate_tax_and_cfop_provenance(): void
    {
        [$company, , , $establishment] = $this->natureOwner();
        $profile = $this->postJson('/api/v1/erp/tax-settings/product-tax-profiles', ['name' => 'Perfil', 'fields' => ['pis_cst' => '01', 'pis_rate' => '0.0000'], 'applicability' => ['kind' => 'goods', 'movement' => 'out']])->assertCreated()->json('data.id');
        $product = $this->natureProduct($company, ['erp_product_tax_profile_id' => $profile, 'tax_field_intents' => ['pis_cst' => 'inherit', 'pis_rate' => 'inherit']]);
        $nature = $this->createNature();
        $choice = $this->choice($nature['id'], '5101', 1);
        $exception = $this->exception($nature['id'], $product, $choice['id'], 2);
        $payload = $this->previewPayload($product, $nature['id'], $establishment);
        ErpOperationNature::factory()->create(['erp_company_id' => $company->id, 'cfop' => '6102']);
        $this->getJson('/api/v1/erp/tax-settings/operation-natures?sort=cfop&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.cfop', '6102');
        $exceptionResponse = $this->postJson(self::PREVIEW, $payload)->assertOk()
            ->assertJsonPath('data.cfop_resolution.version', 'cfop-resolution-v1')
            ->assertJsonPath('data.cfop_resolution.effective_cfop', '5101')->assertJsonPath('data.cfop_resolution.source', 'product_exception')
            ->assertJsonPath('data.cfop_resolution.exception_id', $exception['id'])->assertJsonPath('data.cfop_resolution.configuration_revision', 3)
            ->assertJsonPath('data.product_tax_resolution.values.pis_cst', '01')->assertJsonPath('data.product_tax_resolution.values.pis_rate', '0.0000')
            ->assertJsonPath('data.product_tax_resolution.sources.pis_cst.source', 'profile');
        $explicitResponse = $this->postJson(self::PREVIEW, [...$payload, 'line' => ['cfop' => '5102']])->assertOk()
            ->assertJsonPath('data.cfop_resolution.effective_cfop', '5102')->assertJsonPath('data.cfop_resolution.source', 'explicit')
            ->assertJsonPath('data.cfop_resolution.exception_id', null);
        $otherNature = $this->createNature();
        $this->postJson(self::PREVIEW, [...$payload, 'erp_operation_nature_id' => $otherNature['id']])->assertOk()
            ->assertJsonPath('data.cfop_resolution.effective_cfop', '5102')->assertJsonPath('data.cfop_resolution.source', 'nature_default');
        $this->assertNotSame($exceptionResponse->json('data.fingerprint'), $explicitResponse->json('data.fingerprint'));
        $this->assertSame($exceptionResponse->json('data.product_tax_resolution.fingerprint'), $explicitResponse->json('data.product_tax_resolution.fingerprint'));
        $this->deleteJson(self::NATURES.'/'.$nature['id'].'/product-exceptions/'.$exception['id'], ['expected_revision' => 3])->assertOk();
        $this->postJson(self::PREVIEW, $payload)->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', '5102')->assertJsonPath('data.cfop_resolution.source', 'nature_default');
        $this->patchJson(self::NATURES.'/'.$nature['id'], ['cfop' => '5101', 'expected_revision' => 4])->assertOk();
        $this->getJson(self::NATURES.'/'.$nature['id'].'/choices')->assertOk()->assertJsonPath('data.0.cfop', '5102');
        $this->postJson(self::PREVIEW, $payload)->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', '5101')->assertJsonPath('data.cfop_resolution.source', 'nature_default');
        foreach (['erp_sales', 'erp_stock_movements', 'erp_obligations', 'erp_fiscal_documents'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_invalid_explicit_or_active_exception_target_errors_without_falling_back_but_valid_explicit_ignores_lower_rule(): void
    {
        [$company, , , $establishment] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature();
        $choice = $this->choice($nature['id'], '5101', 1);
        $this->exception($nature['id'], $product, $choice['id'], 2);
        $payload = $this->previewPayload($product, $nature['id'], $establishment);
        $this->postJson(self::PREVIEW, [...$payload, 'line' => ['cfop' => '6102']])->assertUnprocessable()->assertJsonValidationErrors('line.cfop');
        DB::table('erp_operation_nature_cfop_choices')->where('id', $choice['id'])->update(['status' => 'archived']);
        $this->postJson(self::PREVIEW, $payload)->assertUnprocessable()->assertJsonValidationErrors('line.cfop');
        $this->postJson(self::PREVIEW, [...$payload, 'line' => ['cfop' => '5102']])->assertOk()->assertJsonPath('data.cfop_resolution.source', 'explicit');
    }

    public function test_unverified_legacy_default_and_absent_nature_remain_unresolved_without_fabricated_reference(): void
    {
        [$company, , , $establishment] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id, 'cfop' => '5102']);
        $payload = $this->previewPayload($product, $nature->id, $establishment);
        $this->postJson(self::PREVIEW, $payload)->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', null)
            ->assertJsonPath('data.cfop_resolution.reference_version', null)->assertJsonPath('data.cfop_resolution.unresolved_fields.0', 'line.cfop');
        $this->postJson(self::PREVIEW, [...$payload, 'erp_operation_nature_id' => null])->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', null);
        $this->patchJson(self::NATURES.'/'.$nature->id, ['cfop' => '5102', 'expected_revision' => 1])->assertOk();
        $this->postJson(self::PREVIEW, $payload)->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', '5102')->assertJsonPath('data.cfop_resolution.reference_version', 'IT 2023.002 v2.10');
    }

    public function test_missing_actual_context_stays_unresolved_while_incompatible_actual_context_returns_errors(): void
    {
        [$company, , , $establishment] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature();
        $payload = $this->previewPayload($product, $nature['id'], $establishment);
        foreach (['erp_establishment_id', 'movement', 'origin_uf', 'destination_uf', 'model', 'purpose'] as $field) {
            $context = $payload['context'];
            unset($context[$field]);
            $response = $this->postJson(self::PREVIEW, [...$payload, 'context' => $context])->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', null);
            $this->assertContains('context.'.$field, $response->json('data.cfop_resolution.unresolved_fields'));
        }
        $this->postJson(self::PREVIEW, [...$payload, 'context' => $this->operationContext($establishment, ['destination_uf' => 'RJ'])])->assertUnprocessable()->assertJsonValidationErrors('line.cfop');
        $this->postJson(self::PREVIEW, [...$payload, 'context' => $this->operationContext($establishment, ['movement' => 'in'])])->assertUnprocessable()->assertJsonValidationErrors('context.movement');
        $this->postJson(self::PREVIEW, [...$payload, 'context' => $this->operationContext($establishment, ['model' => '65', 'final_consumer' => true, 'presence' => '1'])])->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', '5102');
        $this->postJson(self::PREVIEW, [...$payload, 'context' => $this->operationContext($establishment, ['model' => '65'])])->assertOk()->assertJsonPath('data.cfop_resolution.effective_cfop', null);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedReferences(): iterable
    {
        yield 'fuel' => ['5656'];
        yield 'export' => ['7102'];
        yield 'ISS' => ['5933'];
        yield 'donation' => ['5910'];
        yield 'conditional UF' => ['5949'];
    }

    #[DataProvider('unsupportedReferences')]
    public function test_reference_membership_alone_does_not_certify_unsupported_goods_sale_contexts(string $code): void
    {
        [$company, , , $establishment] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature(['cfop' => $code]);
        $this->postJson(self::PREVIEW, $this->previewPayload($product, $nature['id'], $establishment))->assertOk()
            ->assertJsonPath('data.cfop_resolution.effective_cfop', null)->assertJsonPath('data.cfop_resolution.applicability.status', 'unsupported');
    }

    public function test_operational_preview_uses_exact_source_write_authority_without_granting_nature_or_profile_administration(): void
    {
        [$company, , $membership, $establishment] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.sales.store']]);
        $payload = $this->previewPayload($product, $nature['id'], $establishment, ['line' => ['cfop' => '5102']]);
        $this->postJson(self::PREVIEW, $payload)->assertOk()->assertJsonPath('data.cfop_resolution.source', 'explicit');
        $this->postJson(self::PREVIEW, [...$payload, 'source_action' => 'erp.quotes.store'])->assertForbidden();
        $this->postJson(self::PREVIEW, [...$payload, 'source_action' => 'erp.operation-natures.update'])->assertUnprocessable();
        $this->postJson(self::NATURES.'/'.$nature['id'].'/choices', ['cfop' => '5101', 'expected_revision' => 1])->assertForbidden();
        $membership->update(['role' => 'viewer', 'action_permissions' => ['erp.operation-natures.index']]);
        $this->postJson(self::PREVIEW, $payload)->assertForbidden();
    }

    public function test_unavailable_reference_and_archived_selected_profile_never_produce_a_successful_fallback(): void
    {
        [$company, , , $establishment] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature();
        $payload = $this->previewPayload($product, $nature['id'], $establishment);
        config(['erp.cfop_reference_path' => base_path('missing-cfop-reference.json')]);
        $this->postJson(self::PREVIEW, $payload)->assertStatus(503)->assertJsonPath('code', 'cfop_reference_unavailable');
        config(['erp.cfop_reference_path' => app_path('Erp/CfopReference/Data/cfop-v210-rows.json')]);
        $profile = ErpProductTaxProfile::factory()->create(['erp_company_id' => $company->id, 'status' => 'archived']);
        $product->update(['erp_product_tax_profile_id' => $profile->id]);
        $this->postJson(self::PREVIEW, $payload)->assertUnprocessable()->assertJsonValidationErrors('erp_product_tax_profile_id');
    }

    public function test_line_choice_presence_semantics_preserve_omit_clear_null_and_reject_zero_without_profile_inheritance(): void
    {
        $resolver = app(ErpCfopResolver::class);
        $this->assertSame('5101', $resolver->explicitChoice([], ['cfop' => '5101']));
        $this->assertNull($resolver->explicitChoice(['cfop' => null], ['cfop' => '5101']));
        $this->assertNull($resolver->explicitChoice([]));
        $this->assertNull($resolver->explicitChoice(['cfop' => null]));
        $this->expectException(ValidationException::class);
        $resolver->explicitChoice(['cfop' => 0]);
    }
}
