<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCatalogAttribution;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpProductTaxProfileTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/erp/tax-settings/product-tax-profiles';

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    /** @return array{ErpCompany, User, ErpCompanyMembership} */
    private function owner(): array
    {
        $company = $this->company();
        $user = User::factory()->create();
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->login($user, $company);

        return [$company, $user, $membership];
    }

    private function company(): ErpCompany
    {
        $company = ErpCompany::factory()->create(['operating_settings' => ['regime' => '3']]);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);

        return $company;
    }

    private function login(User $user, ErpCompany $company): void
    {
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
    }

    private function profile(array $extra = []): int
    {
        return $this->postJson(self::URL, array_replace([
            'name' => 'Tributação revista',
            'fields' => ['icms_cst' => '00', 'pis_cst' => '01', 'pis_rate' => '1.6500', 'cofins_rate' => '0.0000'],
            'applicability' => ['tax_regime' => '3', 'kind' => 'goods'],
        ], $extra))->assertCreated()->json('data.id');
    }

    private function product(ErpCompany $company, array $extra = []): ErpProduct
    {
        return ErpProduct::factory()->create([
            'erp_company_id' => $company->id,
            'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $company->id])->id,
            ...$extra,
        ]);
    }

    public function test_profile_lifecycle_retains_exact_attributed_immutable_revisions_and_paginated_history(): void
    {
        [$company, $user, $membership] = $this->owner();
        $id = $this->profile();
        $before = DB::table('erp_product_tax_profile_revisions')->where('erp_product_tax_profile_id', $id)->sole();
        $this->assertSame($user->id, $before->actor_user_id);
        $this->assertSame($membership->id, $before->erp_company_membership_id);
        $this->assertSame('01', json_decode($before->fields, true)['pis_cst']);
        $this->assertSame('0.0000', json_decode($before->fields, true)['cofins_rate']);
        $this->patchJson(self::URL.'/'.$id, ['fields' => ['pis_rate' => '2.0000']])
            ->assertOk()->assertJsonPath('data.current_revision', 2)
            ->assertJsonPath('data.fields.pis_cst', '01')->assertJsonPath('data.fields.pis_rate', '2.0000');
        $this->deleteJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.current_revision', 3);
        $this->assertEquals($before, DB::table('erp_product_tax_profile_revisions')->where('id', $before->id)->sole());
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 3);
        $this->getJson(self::URL.'?status=archived&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->assertSame(['created', 'updated', 'archived'], DB::table('erp_catalog_attributions')->where('resource_type', 'product_tax_profile')->pluck('action')->all());
        $this->assertDatabaseHas('erp_product_tax_profiles', ['id' => $id, 'erp_company_id' => $company->id]);
    }

    /** @return iterable<string, array{array, string}> */
    public static function invalidFields(): iterable
    {
        yield 'numeric code' => [['fields' => ['pis_cst' => 1]], 'fields.pis_cst'];
        yield 'short code' => [['fields' => ['icms_cst' => '0']], 'fields.icms_cst'];
        yield 'numeric rate' => [['fields' => ['pis_rate' => 0]], 'fields.pis_rate'];
        yield 'excess precision' => [['fields' => ['pis_rate' => '1.12345']], 'fields.pis_rate'];
        yield 'operational cfop' => [['fields' => ['cfop' => '5102']], 'fields.cfop'];
        yield 'universal ncm' => [['fields' => ['ncm' => '12345678']], 'fields.ncm'];
        yield 'invalid applicability' => [['applicability' => ['tax_regime' => '9']], 'applicability.tax_regime'];
    }

    #[DataProvider('invalidFields')]
    public function test_profile_rejects_invalid_exact_fields_without_partial_revision(array $extra, string $field): void
    {
        $this->owner();
        $this->postJson(self::URL, ['name' => 'Inválido', ...$extra])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('erp_product_tax_profiles', 0);
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 0);
    }

    public function test_foreign_reads_are_404_and_product_relationship_is_422(): void
    {
        [$company] = $this->owner();
        $id = $this->profile();
        $other = $this->company();
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $other->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->login($user, $other);
        $this->getJson(self::URL.'/'.$id)->assertNotFound();
        $product = $this->product($other);
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['erp_product_tax_profile_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('erp_product_tax_profile_id');
        $this->assertNull($product->fresh()->erp_product_tax_profile_id);
        $this->assertDatabaseHas('erp_product_tax_profiles', ['id' => $id, 'erp_company_id' => $company->id]);
    }

    public function test_viewer_and_revoked_writer_cannot_create_revisions(): void
    {
        [, , $membership] = $this->owner();
        $id = $this->profile();
        $membership->update(['role' => 'viewer', 'action_permissions' => ['*']]);
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Denied'])->assertForbidden();
        $membership->delete();
        $this->deleteJson(self::URL.'/'.$id)->assertForbidden();
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 1);
    }

    public function test_authority_is_rechecked_after_request_context_resolution_before_revision_creation(): void
    {
        [, , $membership] = $this->owner();
        $id = $this->profile();
        $triggered = false;
        $event = 'eloquent.retrieved: '.ErpCompany::class;
        Event::listen($event, function () use ($membership, &$triggered): void {
            if ($triggered || ! request()->attributes->get(CurrentCompany::class) instanceof CurrentCompany) {
                return;
            }
            $triggered = true;
            $membership->update(['role' => 'viewer']);
        });
        try {
            $this->patchJson(self::URL.'/'.$id, ['name' => 'Denied'])->assertForbidden();
        } finally {
            Event::forget($event);
        }
        $this->assertTrue($triggered);
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 1);
    }

    public function test_exact_profile_grants_do_not_grant_product_mutation_and_preview_uses_product_write_grant(): void
    {
        [$company, , $membership] = $this->owner();
        $id = $this->profile();
        $product = $this->product($company);
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.product-tax-profiles.index', 'erp.product-tax-profiles.update']]);
        $this->getJson(self::URL.'/'.$id)->assertOk();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Permitido'])->assertOk();
        $this->postJson(self::URL, ['name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id])->assertForbidden();
        $membership->update(['action_permissions' => ['erp.products.update']]);
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id])->assertOk();
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_explicit_delegation_is_attributed_and_revocation_blocks_later_writes(): void
    {
        $company = $this->company();
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $account = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'operador']);
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $account->id, 'authorized_by_user_id' => $owner->id, 'permission_allowlist' => ['erp.product-tax-profiles.index', 'erp.product-tax-profiles.store']]);
        $this->login($user, $company);
        $id = $this->profile();
        $this->assertDatabaseHas('erp_catalog_attributions', ['resource_type' => 'product_tax_profile', 'resource_id' => $id, 'access_kind' => 'delegated', 'account_id' => $account->id, 'erp_office_delegation_id' => $delegation->id]);
        $delegation->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson(self::URL, ['name' => 'Denied'])->assertForbidden();
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 1);
    }

    public function test_two_products_share_profile_and_pis_override_preserves_zero_and_provenance(): void
    {
        [$company] = $this->owner();
        $id = $this->profile();
        foreach (['First', 'Second'] as $name) {
            $product = $this->product($company, ['name' => $name]);
            $this->patchJson('/api/v1/erp/products/'.$product->id, [
                'erp_product_tax_profile_id' => $id,
                'tax_field_intents' => ['icms_cst' => 'inherit', 'pis_cst' => 'inherit', 'pis_rate' => 'value', 'cofins_rate' => 'inherit'],
                'pis_rate' => '0.0000',
            ])->assertOk();
            $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id])
                ->assertOk()->assertJsonPath('data.values.pis_cst', '01')->assertJsonPath('data.values.pis_rate', '0.0000')
                ->assertJsonPath('data.sources.pis_cst.source', 'profile')->assertJsonPath('data.sources.pis_rate.source', 'product')
                ->assertJsonPath('data.sources.pis_cst.profile_id', $id)->assertJsonPath('data.sources.pis_cst.revision', 1);
        }
    }

    public function test_legacy_omission_and_null_clear_never_adopt_inheritance(): void
    {
        [$company] = $this->owner();
        $id = $this->profile();
        $product = $this->product($company, ['pis_cst' => '02', 'pis_rate' => '0.0000']);
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['name' => 'Unrelated'])->assertOk();
        $this->assertNull($product->fresh()->tax_field_intents);
        $this->assertSame('02', $product->fresh()->pis_cst);
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['erp_product_tax_profile_id' => $id])->assertOk();
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id])->assertOk()
            ->assertJsonPath('data.values.pis_cst', '02')->assertJsonPath('data.values.icms_cst', null);
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['pis_rate' => null])->assertOk();
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id])->assertOk()
            ->assertJsonPath('data.values.pis_rate', null)->assertJsonPath('data.sources.pis_rate.source', 'unset');
    }

    public function test_intent_conflicts_are_atomic_and_unset_stops_fallback(): void
    {
        [$company] = $this->owner();
        $product = $this->product($company, ['pis_rate' => '1.0000']);
        foreach ([['pis_rate' => '0.0000', 'tax_field_intents' => ['pis_rate' => 'inherit']], ['pis_rate' => null, 'tax_field_intents' => ['pis_rate' => 'value']], ['tax_field_intents' => ['cfop' => 'inherit']]] as $payload) {
            $this->patchJson('/api/v1/erp/products/'.$product->id, ['name' => 'Must roll back', ...$payload])->assertUnprocessable();
            $this->assertNotSame('Must roll back', $product->fresh()->name);
        }
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['tax_field_intents' => ['pis_rate' => 'unset']])->assertOk();
        $this->assertNull($product->fresh()->pis_rate);
    }

    public function test_selected_profile_errors_never_fall_through_to_company_defaults(): void
    {
        [$company] = $this->owner();
        $id = $this->profile();
        $product = $this->product($company);
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['erp_product_tax_profile_id' => $id, 'tax_field_intents' => ['pis_cst' => 'inherit']])->assertOk();
        $this->deleteJson(self::URL.'/'.$id)->assertOk();
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id])->assertUnprocessable()->assertJsonValidationErrors('erp_product_tax_profile_id');
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['name' => 'Still editable'])->assertOk();
        $this->postJson('/api/v1/erp/products/tax-preview', ['erp_product_tax_profile_id' => $id, 'kind' => 'goods'])->assertUnprocessable();
        $other = $this->profile(['name' => 'Service only', 'applicability' => ['kind' => 'service']]);
        $this->postJson('/api/v1/erp/products/tax-preview', ['erp_product_tax_profile_id' => $other, 'kind' => 'goods'])->assertUnprocessable()->assertJsonValidationErrors('erp_product_tax_profile_id');
        $this->postJson('/api/v1/erp/products/tax-preview', ['erp_product_tax_profile_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('erp_product_tax_profile_id');
    }

    public function test_preview_reviews_adoption_without_mutating_product_and_basic_save_is_possible(): void
    {
        [$company] = $this->owner();
        $id = $this->profile();
        $product = $this->product($company);
        $before = $product->fresh()->getAttributes();
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id, 'erp_product_tax_profile_id' => $id, 'tax_field_intents' => ['pis_cst' => 'inherit']])
            ->assertOk()->assertJsonPath('data.values.pis_cst', '01')->assertJsonPath('data.previous.values.pis_cst', null);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->postJson('/api/v1/erp/products', ['name' => 'Basic', 'erp_unit_id' => $product->erp_unit_id])->assertCreated()->assertJsonPath('data.erp_product_tax_profile_id', null);
    }

    public function test_company_settings_default_is_explicit_revisioned_and_clearable_without_adopting_products(): void
    {
        [$company] = $this->owner();
        $id = $this->profile();
        $product = $this->product($company);
        $this->getJson('/api/v1/erp/company/settings')->assertOk()->assertJsonPath('data.tax_default_revision', 0)->assertJsonPath('data.default_product_tax_profile_id', null);
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => $id, 'expected_tax_default_revision' => 0])
            ->assertOk()->assertJsonPath('data.default_product_tax_profile_id', $id)->assertJsonPath('data.tax_default_revision', 1);
        $this->patchJson('/api/v1/erp/company/settings', ['ie' => 'Preserve default'])->assertOk()->assertJsonPath('data.default_product_tax_profile_id', $id)->assertJsonPath('data.tax_default_revision', 1);
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => null, 'expected_tax_default_revision' => 0])->assertConflict();
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => null, 'expected_tax_default_revision' => 1])
            ->assertOk()->assertJsonPath('data.default_product_tax_profile_id', null)->assertJsonPath('data.tax_default_revision', 2);
        $this->assertNull($product->fresh()->tax_field_intents);
        $this->assertNull($product->fresh()->erp_product_tax_profile_id);
        $this->assertSame(['created', 'updated'], DB::table('erp_catalog_attributions')->where('resource_type', 'company_product_tax_default')->pluck('action')->all());
    }

    public function test_invalid_default_setting_rolls_back_other_company_settings_and_profile_write_alone_cannot_change_it(): void
    {
        [$company, , $membership] = $this->owner();
        $before = $company->operating_settings;
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => 999999, 'city' => 'Must roll back'])
            ->assertUnprocessable()->assertJsonValidationErrors('default_product_tax_profile_id');
        $this->assertSame($before, $company->fresh()->operating_settings);
        $id = $this->profile();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.product-tax-profiles.update']]);
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => $id])->assertForbidden();
        $this->assertDatabaseCount('erp_company_product_tax_defaults', 0);
    }

    public function test_product_rejects_explicit_incompatible_profile_relationship_without_changing_other_fields(): void
    {
        [$company] = $this->owner();
        $id = $this->profile(['applicability' => ['kind' => 'service']]);
        $product = $this->product($company);
        $this->patchJson('/api/v1/erp/products/'.$product->id, ['name' => 'Must roll back', 'erp_product_tax_profile_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('erp_product_tax_profile_id');
        $this->assertNull($product->fresh()->erp_product_tax_profile_id);
        $this->assertNotSame('Must roll back', $product->fresh()->name);
    }

    public function test_company_default_foreign_relationship_and_archived_default_preview_do_not_substitute_settings(): void
    {
        [$company] = $this->owner();
        $foreign = ErpProductTaxProfile::factory()->create();
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('default_product_tax_profile_id');
        $id = $this->profile();
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => $id])->assertOk();
        $product = $this->product($company);
        $this->deleteJson(self::URL.'/'.$id)->assertOk();
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id, 'tax_field_intents' => ['pis_cst' => 'inherit']])
            ->assertUnprocessable()->assertJsonValidationErrors('default_product_tax_profile_id');
        $this->postJson('/api/v1/erp/products/tax-preview', ['product_id' => $product->id])->assertOk()->assertJsonPath('data.values.pis_cst', null);
    }

    public function test_stale_profile_revision_and_failed_attribution_roll_back_entire_new_revision(): void
    {
        $this->owner();
        $id = $this->profile();
        $this->patchJson(self::URL.'/'.$id, ['fields' => ['pis_cst' => '02'], 'expected_revision' => 999])->assertConflict();
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 1);
        $event = 'eloquent.creating: '.ErpCatalogAttribution::class;
        Event::listen($event, fn () => throw ValidationException::withMessages(['name' => 'Falha de auditoria injetada.']));
        try {
            $this->patchJson(self::URL.'/'.$id, ['name' => 'Must roll back'])->assertUnprocessable();
        } finally {
            Event::forget($event);
        }
        $this->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('data.current_revision', 1)->assertJsonPath('data.fields.pis_cst', '01');
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 1);
    }
}
