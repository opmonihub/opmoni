<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCarrier;
use App\Models\Erp\ErpCategory;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpDiscountRule;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpPriceGroup;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ErpCatalogTask5ContractTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
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

    /** @return array{user: User, company: ErpCompany, headers: array<string, string>} */
    private function ownerContext(): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->login($user);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-Id' => (string) $company->id],
        ];
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function login(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_product_patch_rejects_unknown_variant_before_changing_parent(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
            'name' => 'Original name',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'name' => 'Changed name',
                'variants' => [['id' => 999999, 'name' => 'Unknown variant']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.id');

        $this->assertSame('Original name', $product->fresh()->name);
    }

    public function test_service_patch_cannot_enable_stock_when_kind_is_omitted(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
            'kind' => 'service',
            'track_stock' => false,
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, ['track_stock' => true])
            ->assertOk()
            ->assertJsonPath('data.track_stock', false);

        $this->assertFalse($product->fresh()->track_stock);
    }

    public function test_unit_patch_validates_the_merged_base_and_factor_pair(): void
    {
        $context = $this->ownerContext();
        $base = ErpUnit::factory()->create([
            'erp_company_id' => $context['company']->id,
            'short_name' => 'm',
        ]);
        $derived = ErpUnit::factory()->create([
            'erp_company_id' => $context['company']->id,
            'short_name' => 'cm',
            'base_unit_id' => $base->id,
            'conversion_factor' => '100.00000000',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/units/'.$derived->id, ['conversion_factor' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversion_factor');

        $this->assertSame('100.00000000', (string) $derived->fresh()->conversion_factor);
    }

    public function test_category_patch_rejects_a_cycle_without_changing_the_parent(): void
    {
        $context = $this->ownerContext();
        $parent = ErpCategory::factory()->create([
            'erp_company_id' => $context['company']->id,
            'parent_id' => null,
            'name' => 'Parent',
        ]);
        $child = ErpCategory::factory()->create([
            'erp_company_id' => $context['company']->id,
            'parent_id' => $parent->id,
            'name' => 'Child',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/categories/'.$parent->id, ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_category_rejects_nonpositive_parent_ids_as_field_errors(): void
    {
        $context = $this->ownerContext();

        foreach ([0, -1] as $parentId) {
            $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
                ->postJson('/api/v1/erp/categories', ['name' => 'Invalid parent '.$parentId, 'parent_id' => $parentId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('parent_id');
        }

        $this->assertDatabaseCount('erp_categories', 0);
    }

    public function test_product_rejects_an_archived_unit_as_a_new_reference(): void
    {
        $context = $this->ownerContext();
        $archivedUnit = ErpUnit::factory()->create([
            'erp_company_id' => $context['company']->id,
            'status' => 'archived',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'New product',
                'erp_unit_id' => $archivedUnit->id,
                'sale_price' => '10.0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_unit_id');
    }

    public function test_product_sku_is_case_sensitive_and_shared_only_with_its_default_variant(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
            'sku' => 'ABC',
        ]);
        ErpProductVariant::factory()->create([
            'erp_product_id' => $product->id,
            'sku' => 'ABC',
            'name' => 'Default variant',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Lowercase SKU',
                'sku' => 'abc',
                'erp_unit_id' => $unit->id,
                'sale_price' => '10.0000',
            ])
            ->assertCreated();

        $mirror = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Mirrored default SKU',
                'sku' => 'MIRROR-1',
                'erp_unit_id' => $unit->id,
                'variants' => [['name' => 'Padrão', 'sku' => 'MIRROR-1']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'MIRROR-1');
        $this->assertSame('MIRROR-1', $mirror->json('data.variants.0.sku'));

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Duplicate SKU',
                'sku' => 'ABC',
                'erp_unit_id' => $unit->id,
                'sale_price' => '10.0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');
    }

    public function test_product_patch_rejects_a_variant_owned_by_another_product_without_partial_changes(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $context['company']->id, 'erp_unit_id' => $unit->id, 'name' => 'First']);
        $other = ErpProduct::factory()->create(['erp_company_id' => $context['company']->id, 'erp_unit_id' => $unit->id]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $other->id, 'name' => 'Owned elsewhere']);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'name' => 'Should roll back',
                'variants' => [['id' => $variant->id, 'name' => 'Hijacked']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.id');

        $this->assertSame('First', $product->fresh()->name);
        $this->assertSame('Owned elsewhere', $variant->fresh()->name);
    }

    public function test_product_create_rejects_variant_ids_and_update_can_edit_and_add_real_variants(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $existing = ErpProductVariant::factory()->create();

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Invalid variant create',
                'erp_unit_id' => $unit->id,
                'variants' => [['id' => $existing->id, 'name' => 'Must not be adopted']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants');

        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
        ]);
        $variant = ErpProductVariant::factory()->create([
            'erp_product_id' => $product->id,
            'name' => 'Before edit',
        ]);
        $response = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'variants' => [
                    ['id' => $variant->id, 'name' => 'Edited variant'],
                    ['name' => 'New real variant', 'sku' => 'VAR-NEW'],
                ],
            ])
            ->assertOk();

        $this->assertSame('Edited variant', $response->json('data.variants.0.name'));
        $this->assertSame('New real variant', $response->json('data.variants.1.name'));
        $this->assertDatabaseCount('erp_product_variants', 3);
    }

    public function test_variant_persistence_failure_rolls_back_product_and_prior_variants_before_http_rendering(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
            'name' => 'Before transaction',
        ]);
        $event = 'eloquent.creating: '.ErpProductVariant::class;
        Event::listen($event, function (ErpProductVariant $variant): void {
            if ($variant->name === 'Trigger rollback') {
                throw new \RuntimeException('Intentional Task 5 rollback test failure');
            }
        });

        try {
            $response = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
                ->patchJson('/api/v1/erp/products/'.$product->id, [
                    'name' => 'Should roll back',
                    'variants' => [
                        ['name' => 'First inserted variant'],
                        ['name' => 'Trigger rollback'],
                    ],
                ]);
        } finally {
            Event::forget($event);
        }

        $response->assertServerError();
        $this->assertSame('Before transaction', $product->fresh()->name);
        $this->assertDatabaseCount('erp_product_variants', 0);
    }

    public function test_variant_archive_is_scoped_and_remains_in_product_detail(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
        ]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'variants' => [['id' => $variant->id, 'name' => $variant->name, 'status' => 'archived']],
            ])
            ->assertOk()
            ->assertJsonPath('data.variants.0.status', 'archived');

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.variants.0.id', $variant->id)
            ->assertJsonPath('data.variants.0.status', 'archived');
    }

    public function test_variant_archive_requires_the_independent_product_archive_permission(): void
    {
        $context = $this->ownerContext();
        ErpCompanyMembership::query()
            ->where('erp_company_id', $context['company']->id)
            ->where('user_id', $context['user']->id)
            ->update(['role' => 'manager', 'action_permissions' => ['erp.products.update']]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
        ]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'variants' => [['id' => $variant->id, 'name' => $variant->name, 'status' => 'archived']],
            ])
            ->assertForbidden();

        $this->assertSame('active', $variant->fresh()->status);
    }

    public function test_product_variant_ids_cannot_be_repeated_in_one_patch(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $context['company']->id, 'erp_unit_id' => $unit->id]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id, 'name' => 'Original']);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'variants' => [
                    ['id' => $variant->id, 'name' => 'First write'],
                    ['id' => $variant->id, 'name' => 'Second write'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.1.id');

        $this->assertSame('Original', $variant->fresh()->name);
    }

    public function test_tax_catalogs_require_active_establishments_for_new_links_but_keep_unchanged_history(): void
    {
        $context = $this->ownerContext();
        $historical = ErpEstablishment::factory()->create([
            'erp_company_id' => $context['company']->id,
            'status' => 'inactive',
        ]);
        $otherInactive = ErpEstablishment::factory()->create([
            'erp_company_id' => $context['company']->id,
            'status' => 'inactive',
        ]);
        $taxRate = ErpTaxRate::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_establishment_id' => $historical->id,
        ]);
        $operationNature = ErpOperationNature::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_establishment_id' => $historical->id,
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/tax-settings/tax-rates', [
                'erp_establishment_id' => $otherInactive->id,
                'name' => 'Inactive tax rate',
                'rate' => '3.0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_establishment_id');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/tax-settings/operation-natures', [
                'erp_establishment_id' => $otherInactive->id,
                'cfop' => '5102',
                'description' => 'Inactive operation nature',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_establishment_id');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/tax-settings/tax-rates/'.$taxRate->id, [
                'erp_establishment_id' => $otherInactive->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_establishment_id');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/tax-settings/operation-natures/'.$operationNature->id, [
                'erp_establishment_id' => $otherInactive->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_establishment_id');

        $this->assertSame($historical->id, $taxRate->fresh()->erp_establishment_id);
        $this->assertSame($historical->id, $operationNature->fresh()->erp_establishment_id);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/tax-settings/tax-rates/'.$taxRate->id, ['name' => 'Historical tax rate'])
            ->assertOk();

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/tax-settings/operation-natures/'.$operationNature->id, ['description' => 'Historical operation nature'])
            ->assertOk();

        $this->assertSame($historical->id, $taxRate->fresh()->erp_establishment_id);
        $this->assertSame($historical->id, $operationNature->fresh()->erp_establishment_id);
    }

    public function test_product_can_keep_an_archived_historical_unit_but_cannot_switch_to_an_archived_unit(): void
    {
        $context = $this->ownerContext();
        $historical = ErpUnit::factory()->create([
            'erp_company_id' => $context['company']->id,
            'status' => 'archived',
        ]);
        $otherArchived = ErpUnit::factory()->create([
            'erp_company_id' => $context['company']->id,
            'status' => 'archived',
        ]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $historical->id,
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'erp_unit_id' => $historical->id,
                'name' => 'Historical reference retained',
            ])
            ->assertOk();

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, ['erp_unit_id' => $otherArchived->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_unit_id');

        $this->assertSame($historical->id, $product->fresh()->erp_unit_id);
    }

    public function test_unit_rejects_self_reference_and_conversion_cycles(): void
    {
        $context = $this->ownerContext();
        $first = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id, 'short_name' => 'u1']);
        $second = ErpUnit::factory()->create([
            'erp_company_id' => $context['company']->id,
            'short_name' => 'u2',
            'base_unit_id' => $first->id,
            'conversion_factor' => '2.00000000',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/units/'.$first->id, [
                'base_unit_id' => $first->id,
                'conversion_factor' => '1.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('base_unit_id');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/units/'.$first->id, [
                'base_unit_id' => $second->id,
                'conversion_factor' => '3.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('base_unit_id');

        $this->assertNull($first->fresh()->base_unit_id);
    }

    public function test_catalog_decimal_width_overflow_returns_field_validation_errors(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Too expensive',
                'erp_unit_id' => $unit->id,
                'sale_price' => '10000000000000000.0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sale_price');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/units', [
                'short_name' => 'wide',
                'actual_name' => 'Out of range',
                'base_unit_id' => $unit->id,
                'conversion_factor' => '1000000000000.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversion_factor');
    }

    public function test_category_and_brand_case_insensitive_active_duplicates_return_field_errors(): void
    {
        $context = $this->ownerContext();
        ErpCategory::factory()->create([
            'erp_company_id' => $context['company']->id,
            'name' => 'Ferramentas',
            'status' => 'active',
        ]);
        ErpBrand::factory()->create([
            'erp_company_id' => $context['company']->id,
            'name' => 'Oficina',
            'status' => 'active',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/categories', ['name' => 'FERRAMENTAS'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/brands', ['name' => 'OFICINA'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_unit_short_name_duplicate_including_archived_row_returns_field_error(): void
    {
        $context = $this->ownerContext();
        ErpUnit::factory()->create([
            'erp_company_id' => $context['company']->id,
            'short_name' => 'kg',
            'status' => 'archived',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/units', ['short_name' => 'kg', 'actual_name' => 'Quilograma'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('short_name');
    }

    public function test_product_sku_conflicts_with_variants_across_the_company_but_not_another_company(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
            'sku' => null,
        ]);
        ErpProductVariant::factory()->create(['erp_product_id' => $product->id, 'sku' => 'PART-9']);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Duplicate variant SKU',
                'sku' => 'PART-9',
                'erp_unit_id' => $unit->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');

        $otherCompany = ErpCompany::factory()->create(['cnpj' => '60746948000122']);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $otherCompany->id,
            'user_id' => $context['user']->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $otherCompany->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'active',
        ]);
        $otherUnit = ErpUnit::factory()->create(['erp_company_id' => $otherCompany->id]);
        $csrf = $this->csrf();
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $otherCompany->id])
            ->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $otherCompany->id, 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Same SKU another company',
                'sku' => 'PART-9',
                'erp_unit_id' => $otherUnit->id,
            ])
            ->assertCreated();
    }

    public function test_price_discount_and_tax_decimal_widths_and_existing_active_uniques_are_field_errors(): void
    {
        $context = $this->ownerContext();
        ErpPriceGroup::factory()->create([
            'erp_company_id' => $context['company']->id,
            'name' => 'Varejo',
        ]);
        ErpDiscountRule::factory()->create([
            'erp_company_id' => $context['company']->id,
            'coupon_code' => 'PROMO10',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/price-groups', ['name' => 'VAREJO'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/price-groups', [
                'name' => 'Out of range adjustment',
                'percentage_adjustment' => '1000000.0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('percentage_adjustment');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/discount-rules', [
                'name' => 'Duplicate coupon',
                'kind' => 'percentage',
                'value' => '10.0000',
                'coupon_code' => 'promo10',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coupon_code');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/discount-rules', [
                'name' => 'Out of range value',
                'kind' => 'fixed',
                'value' => '10000000000000000.0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/tax-settings/tax-rates', [
                'name' => 'Out of range rate',
                'rate' => '10000000.0000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate');
    }

    public function test_vehicle_cannot_be_assigned_a_new_archived_carrier(): void
    {
        $context = $this->ownerContext();
        $carrier = ErpCarrier::factory()->create([
            'erp_company_id' => $context['company']->id,
            'status' => 'archived',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/vehicles', [
                'plate' => 'ABC1D23',
                'erp_carrier_id' => $carrier->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_carrier_id');

        $this->assertDatabaseCount('erp_vehicles', 0);
    }
}
