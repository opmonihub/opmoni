<?php

namespace Tests\Feature;

use App\Erp\ErpOperationNatureRules;
use App\Models\Erp\ErpCatalogAttribution;
use App\Models\Erp\ErpOperationNature;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsOperationNatureHarness;
use Tests\TestCase;

class ErpOperationNatureRulesTest extends TestCase
{
    use BuildsOperationNatureHarness, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureNatureHarness();
    }

    public function test_legacy_scalar_payload_keeps_identity_default_metadata_and_advances_aggregate_revision(): void
    {
        [$company] = $this->natureOwner();
        $nature = $this->createNature(['fiscal_metadata' => ['retained' => ['zero' => 0, 'enabled' => false]]]);
        $this->assertArrayHasKey('configuration_revision', $nature);
        $this->assertSame(1, $nature['configuration_revision']);
        $this->patchJson(self::NATURES.'/'.$nature['id'], ['description' => 'Descrição revista'])
            ->assertOk()->assertJsonPath('data.configuration_revision', 2)->assertJsonPath('data.cfop', '5102')
            ->assertJsonPath('data.fiscal_metadata.retained.zero', 0)->assertJsonPath('data.fiscal_metadata.retained.enabled', false);
        $this->patchJson(self::NATURES.'/'.$nature['id'], ['cfop' => '5101'])
            ->assertOk()->assertJsonPath('data.configuration_revision', 3)->assertJsonPath('data.id', $nature['id']);
        $this->deleteJson(self::NATURES.'/'.$nature['id'])->assertOk()->assertJsonPath('data.configuration_revision', 4);
        $this->assertDatabaseHas('erp_operation_natures', ['id' => $nature['id'], 'erp_company_id' => $company->id, 'status' => 'archived']);
    }

    public function test_legacy_unverified_default_is_virtual_without_backfill_and_unrelated_edits_do_not_certify_it(): void
    {
        [$company] = $this->natureOwner();
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id, 'cfop' => '5.102', 'fiscal_metadata' => ['legacy' => true]]);
        $this->getJson(self::NATURES.'/'.$nature->id.'/choices')->assertOk()->assertJsonPath('data.0.cfop', '5.102')
            ->assertJsonPath('data.0.implicit', true)->assertJsonPath('data.0.reference_version', null);
        $this->assertDatabaseCount('erp_operation_nature_cfop_choices', 0);
        $this->patchJson(self::NATURES.'/'.$nature->id, ['description' => 'Legado preservado'])->assertOk()
            ->assertJsonPath('data.cfop', '5.102')->assertJsonPath('data.cfop_reference_version', null);
        $this->assertDatabaseCount('erp_operation_nature_cfop_choices', 0);
    }

    public function test_unrelated_legacy_full_payload_does_not_certify_an_unchanged_canonical_default(): void
    {
        [$company] = $this->natureOwner();
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id, 'cfop' => '5102']);
        $this->patchJson(self::NATURES.'/'.$nature->id, ['cfop' => '5102', 'description' => 'Descrição revista', 'movement' => 'out'])
            ->assertOk()->assertJsonPath('data.cfop_reference_version', null);
        $this->assertDatabaseCount('erp_operation_nature_cfop_choices', 0);
        $this->patchJson(self::NATURES.'/'.$nature->id, ['cfop' => '5102', 'expected_revision' => 2])->assertOk()->assertJsonPath('data.cfop_reference_version', 'IT 2023.002 v2.10');
    }

    public function test_unchanged_scalar_only_confirms_a_legacy_reference_only_with_the_reviewed_revision(): void
    {
        [$company] = $this->natureOwner();
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id, 'cfop' => '5102']);
        $url = self::NATURES.'/'.$nature->id;
        $this->patchJson($url, ['cfop' => '5102'])->assertOk()->assertJsonPath('data.cfop_reference_version', null)->assertJsonPath('data.configuration_revision', 1);
        $this->assertDatabaseCount('erp_catalog_attributions', 0);
        $this->assertDatabaseCount('erp_operation_nature_cfop_choices', 0);
        $this->patchJson($url, ['cfop' => '5102', 'expected_revision' => null])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->patchJson($url, ['cfop' => '5102', 'expected_revision' => 0])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->patchJson($url, ['cfop' => '5102', 'expected_revision' => 99])->assertConflict();
        $this->patchJson($url, ['cfop' => '5102', 'expected_revision' => 1])->assertOk()->assertJsonPath('data.cfop_reference_version', 'IT 2023.002 v2.10')->assertJsonPath('data.configuration_revision', 2);
    }

    public function test_default_is_explicit_and_collection_replacement_is_atomic_and_revision_bound(): void
    {
        $this->natureOwner();
        $nature = $this->createNature();
        $url = self::NATURES.'/'.$nature['id'];
        $this->patchJson($url, ['allowed_cfops' => ['5101', '5102'], 'cfop' => '5102'])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->patchJson($url, ['allowed_cfops' => ['5101', '5102'], 'cfop' => '5102', 'expected_revision' => 1])->assertOk()
            ->assertJsonPath('data.cfop', '5102')->assertJsonPath('data.configuration_revision', 2);
        $this->patchJson($url, ['allowed_cfops' => ['5101'], 'description' => 'Must roll back', 'expected_revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('cfop');
        $this->getJson($url)->assertOk()->assertJsonPath('data.description', 'Venda de mercadoria')->assertJsonPath('data.configuration_revision', 2);
        $this->patchJson($url, ['allowed_cfops' => ['5101', '5101'], 'expected_revision' => 2])->assertUnprocessable();
        $this->patchJson($url, ['allowed_cfops' => ['5101', '9999'], 'expected_revision' => 2])->assertUnprocessable();
        $this->patchJson($url, ['allowed_cfops' => ['5101'], 'cfop' => '5101', 'expected_revision' => 1])->assertConflict();
        $this->patchJson($url, ['allowed_cfops' => ['5101'], 'cfop' => '5101', 'expected_revision' => 2])->assertOk()->assertJsonPath('data.configuration_revision', 3);
        $this->getJson($url.'/choices?status=archived')->assertOk()->assertJsonPath('data.0.cfop', '5102');
    }

    public function test_choice_archive_requires_atomic_default_replacement_and_keeps_identity(): void
    {
        $this->natureOwner();
        $nature = $this->createNature();
        $default = $this->getJson(self::NATURES.'/'.$nature['id'].'/choices')->assertOk()->json('data.0.id');
        $this->choice($nature['id'], '5101', 1);
        $url = self::NATURES.'/'.$nature['id'].'/choices/'.$default;
        $this->deleteJson($url, ['expected_revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('cfop');
        $this->deleteJson($url, ['expected_revision' => 2, 'cfop' => '5101'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $default);
        $this->getJson(self::NATURES.'/'.$nature['id'])->assertOk()->assertJsonPath('data.cfop', '5101')->assertJsonPath('data.configuration_revision', 3);
    }

    public function test_exceptions_are_unique_attributed_paginated_archived_and_never_create_business_effects(): void
    {
        [$company, $user, $membership] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature();
        $choice = $this->choice($nature['id'], '5101', 1);
        $exception = $this->exception($nature['id'], $product, $choice['id'], 2);
        $url = self::NATURES.'/'.$nature['id'].'/product-exceptions';
        $this->postJson($url, ['erp_product_id' => $product->id, 'erp_operation_nature_cfop_choice_id' => $choice['id'], 'expected_revision' => 3])->assertUnprocessable()->assertJsonValidationErrors('erp_product_id');
        $this->getJson($url.'?per_page=1&search='.$product->name)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $exception['id']);
        $this->getJson($url.'/'.$exception['id'])->assertOk()->assertJsonPath('data.erp_product_id', $product->id);
        $this->deleteJson($url.'/'.$exception['id'], ['expected_revision' => 2])->assertConflict();
        $this->deleteJson($url.'/'.$exception['id'], ['expected_revision' => 3])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->exception($nature['id'], $product, $choice['id'], 4);
        $this->assertDatabaseCount('erp_operation_nature_product_exceptions', 2);
        $audit = DB::table('erp_catalog_attributions')->where('resource_type', 'operation_nature_exception')->where('resource_id', $exception['id'])->first();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame($membership->id, $audit->erp_company_membership_id);
        foreach (['erp_stock_movements', 'erp_obligations', 'erp_fiscal_documents', 'erp_sales'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_foreign_direct_reads_are_404_but_foreign_products_and_other_nature_choices_are_422(): void
    {
        [$company] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $foreign = ErpOperationNature::factory()->create();
        $nature = $this->createNature();
        $other = $this->createNature();
        $choice = $this->choice($other['id'], '5101', 1);
        $this->getJson(self::NATURES.'/'.$foreign->id.'/choices')->assertNotFound();
        $url = self::NATURES.'/'.$nature['id'].'/product-exceptions';
        $this->postJson($url, ['erp_product_id' => $product->id, 'erp_operation_nature_cfop_choice_id' => $choice['id'], 'expected_revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('erp_operation_nature_cfop_choice_id');
        $choice = $this->choice($nature['id'], '5101', 1);
        $foreignProduct = $this->natureProduct($foreign->company);
        $this->postJson($url, ['erp_product_id' => $foreignProduct->id, 'erp_operation_nature_cfop_choice_id' => $choice['id'], 'expected_revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('erp_product_id');
        $this->assertDatabaseCount('erp_operation_nature_product_exceptions', 0);
    }

    public function test_product_or_source_write_never_grants_rule_administration_and_revocation_has_no_partial_write(): void
    {
        [, , $membership] = $this->natureOwner();
        $nature = $this->createNature();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.products.update', 'erp.sales.store', 'erp.operation-natures.index']]);
        $this->getJson(self::NATURES.'/'.$nature['id'].'/choices')->assertOk();
        $this->postJson(self::NATURES.'/'.$nature['id'].'/choices', ['cfop' => '5101', 'expected_revision' => 1])->assertForbidden();
        $membership->update(['action_permissions' => ['erp.operation-natures.update']]);
        $this->choice($nature['id'], '5101', 1);
        $membership->delete();
        $this->patchJson(self::NATURES.'/'.$nature['id'], ['cfop' => '5101'])->assertForbidden();
        $this->assertDatabaseHas('erp_operation_natures', ['id' => $nature['id'], 'cfop' => '5102', 'configuration_revision' => 2]);
    }

    public function test_audit_failure_rolls_back_children_default_and_revision_as_one_transaction(): void
    {
        $this->natureOwner();
        $nature = $this->createNature();
        $event = 'eloquent.creating: '.ErpCatalogAttribution::class;
        Event::listen($event, fn () => throw ValidationException::withMessages(['audit' => 'Falha injetada.']));
        try {
            $this->patchJson(self::NATURES.'/'.$nature['id'], ['cfop' => '5101', 'allowed_cfops' => ['5101'], 'expected_revision' => 1])->assertUnprocessable();
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseHas('erp_operation_natures', ['id' => $nature['id'], 'cfop' => '5102', 'configuration_revision' => 1]);
        $this->assertDatabaseMissing('erp_operation_nature_cfop_choices', ['cfop' => '5101']);
    }

    public function test_used_active_choice_cannot_be_archived_and_default_change_never_rewrites_a_redundant_exception(): void
    {
        [$company] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature();
        $choice = $this->choice($nature['id'], '5101', 1);
        $exception = $this->exception($nature['id'], $product, $choice['id'], 2);
        $this->deleteJson(self::NATURES.'/'.$nature['id'].'/choices/'.$choice['id'], ['expected_revision' => 3])->assertUnprocessable();
        $this->patchJson(self::NATURES.'/'.$nature['id'], ['cfop' => '5101'])->assertOk()->assertJsonPath('data.configuration_revision', 4);
        $this->assertDatabaseHas('erp_operation_nature_product_exceptions', ['id' => $exception['id'], 'erp_operation_nature_cfop_choice_id' => $choice['id'], 'status' => 'active']);
        $this->getJson(self::NATURES.'/'.$nature['id'].'/product-exceptions')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_invalid_scalar_and_movement_incompatible_choice_roll_back_legacy_and_collection_writes(): void
    {
        $this->natureOwner();
        $nature = $this->createNature();
        $this->patchJson(self::NATURES.'/'.$nature['id'], ['description' => 'Must roll back', 'cfop' => '5.102'])->assertUnprocessable()->assertJsonValidationErrors('cfop');
        $this->postJson(self::NATURES.'/'.$nature['id'].'/choices', ['cfop' => '1102', 'expected_revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('cfop');
        $this->getJson(self::NATURES.'/'.$nature['id'])->assertOk()->assertJsonPath('data.configuration_revision', 1)->assertJsonPath('data.description', 'Venda de mercadoria');
    }

    public function test_scalar_direction_change_archives_only_the_unreferenced_previous_single_default_atomically(): void
    {
        [$company] = $this->natureOwner();
        $nature = $this->createNature();
        $choice = $this->getJson(self::NATURES.'/'.$nature['id'].'/choices')->assertOk()->json('data.0.id');
        $this->patchJson(self::NATURES.'/'.$nature['id'], ['cfop' => '1102', 'movement' => 'in'])->assertOk()
            ->assertJsonPath('data.cfop', '1102')->assertJsonPath('data.movement', 'in')->assertJsonPath('data.configuration_revision', 2);
        $this->getJson(self::NATURES.'/'.$nature['id'].'/choices/'.$choice)->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.id', $choice);
        $multi = $this->createNature();
        $this->choice($multi['id'], '5101', 1);
        $this->patchJson(self::NATURES.'/'.$multi['id'], ['cfop' => '1102', 'movement' => 'in'])->assertUnprocessable();
        $this->assertDatabaseHas('erp_operation_natures', ['id' => $multi['id'], 'cfop' => '5102', 'movement' => 'out', 'configuration_revision' => 2]);
        $used = $this->createNature();
        $usedChoice = $this->getJson(self::NATURES.'/'.$used['id'].'/choices')->assertOk()->json('data.0.id');
        $this->exception($used['id'], $this->natureProduct($company), $usedChoice, 1);
        $this->patchJson(self::NATURES.'/'.$used['id'], ['cfop' => '1102', 'movement' => 'in'])->assertUnprocessable();
        $this->assertDatabaseHas('erp_operation_nature_cfop_choices', ['id' => $usedChoice, 'status' => 'active']);
        $this->assertDatabaseHas('erp_operation_natures', ['id' => $used['id'], 'cfop' => '5102', 'movement' => 'out', 'configuration_revision' => 2]);
        foreach (['erp_stock_movements', 'erp_obligations', 'erp_fiscal_documents'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_exception_edit_requires_reviewed_revision_and_archive_of_nature_retains_children_but_blocks_new_use(): void
    {
        [$company] = $this->natureOwner();
        $product = $this->natureProduct($company);
        $nature = $this->createNature();
        $first = $this->choice($nature['id'], '5101', 1);
        $exception = $this->exception($nature['id'], $product, $first['id'], 2);
        $default = $this->getJson(self::NATURES.'/'.$nature['id'].'/choices?search=5102')->assertOk()->json('data.0.id');
        $url = self::NATURES.'/'.$nature['id'].'/product-exceptions/'.$exception['id'];
        $this->patchJson($url, ['erp_operation_nature_cfop_choice_id' => $default])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->patchJson($url, ['erp_operation_nature_cfop_choice_id' => $default, 'expected_revision' => 2])->assertConflict();
        $this->patchJson($url, ['erp_operation_nature_cfop_choice_id' => $default, 'expected_revision' => 3])->assertOk()->assertJsonPath('data.erp_operation_nature_cfop_choice_id', $default);
        $this->deleteJson(self::NATURES.'/'.$nature['id'])->assertOk()->assertJsonPath('data.configuration_revision', 5);
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $exception['id']);
        $this->postJson(self::NATURES.'/'.$nature['id'].'/choices', ['cfop' => '5103', 'expected_revision' => 5])->assertUnprocessable();
    }

    public static function unprotectedWriters(): iterable
    {
        yield 'choice insertion' => ['choice'];
        yield 'revision advancement' => ['advance'];
    }

    #[DataProvider('unprotectedWriters')]
    public function test_public_writers_reject_unprotected_invocation_before_persisting_anything(string $writer): void
    {
        [$company, $user, $membership] = $this->natureOwner();
        $nature = ErpOperationNature::query()->findOrFail($this->createNature()['id']);
        $before = $nature->getAttributes();
        $request = Request::create('/api/v1/erp/tax-settings/operation-natures/'.$nature->id, 'PATCH');
        $request->setUserResolver(fn () => $user);
        $request->setRouteResolver(fn () => (new Route('PATCH', '/nature', fn () => null))->name('erp.operation-natures.update'));
        $context = new CurrentCompany($company, 'direct', $membership, null, null);
        $request->attributes->set(CurrentCompany::class, $context);
        try {
            if ($writer === 'choice') {
                app(ErpOperationNatureRules::class)->ensureChoice($request, $context, $nature, '5101');
            } else {
                app(ErpOperationNatureRules::class)->advance($request, $context, $nature);
            }
            $this->fail('Unprotected configuration write was accepted.');
        } catch (\LogicException) {
            $this->assertSame($before, $nature->fresh()->getAttributes());
            $this->assertDatabaseMissing('erp_operation_nature_cfop_choices', ['cfop' => '5101']);
        }
    }
}
