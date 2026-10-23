<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCatalogPrinterProfile;
use App\Models\Erp\ErpCatalogPrintLayout;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpCatalogLabelConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Label feature tests require isolated SQLite memory before RefreshDatabase.');
        }

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

    public function test_product_index_exposes_owned_variants_for_the_label_selector_without_foreign_rows(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['name' => 'Café', 'barcode' => '00012345678905']);
        $variant = ErpProductVariant::factory()->create([
            'erp_product_id' => $product->id,
            'name' => 'Caixa',
            'barcode' => '04252614',
            'barcode_type' => 'UPCE',
            'sale_price' => '0.0000',
        ]);
        $archived = ErpProductVariant::factory()->create(['erp_product_id' => $product->id, 'status' => 'archived']);
        $foreignCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignProduct = $this->product($foreignCompany);
        $foreignVariant = ErpProductVariant::factory()->create(['erp_product_id' => $foreignProduct->id]);

        $response = $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonCount(2, 'data.0.variants')
            ->assertJsonPath('data.0.variants.0.id', $variant->id)
            ->assertJsonPath('data.0.variants.0.barcode', '04252614')
            ->assertJsonPath('data.0.variants.0.barcode_type', 'UPCE')
            ->assertJsonPath('data.0.variants.0.sale_price', '0.0000')
            ->assertJsonPath('data.0.variants.1.id', $archived->id)
            ->assertJsonPath('data.0.variants.1.status', 'archived');
        $this->assertNotContains($foreignVariant->id, array_column($response->json('data.0.variants'), 'id'));
    }

    public function test_typed_product_label_request_preserves_selection_order_and_variant_quantity(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_unit_id' => $unit->id,
            'name' => 'Café & Chá',
            'sku' => 'PROD-1',
            'barcode' => '4006381333931',
            'sale_price' => '12.3400',
        ]);
        $variant = ErpProductVariant::factory()->create([
            'erp_product_id' => $product->id,
            'name' => 'Caixa vermelha',
            'sku' => '0',
            'barcode' => '036000291452',
            'sale_price' => '0.0000',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [
                    ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 3],
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.variant_id', $variant->id)
            ->assertJsonPath('data.0.variant_name', 'Caixa vermelha')
            ->assertJsonPath('data.0.sku', '0')
            ->assertJsonPath('data.0.barcode', '036000291452')
            ->assertJsonPath('data.0.sale_price', '0.0000')
            ->assertJsonPath('data.0.quantity', 3)
            ->assertJsonPath('data.1.variant_id', null)
            ->assertJsonPath('data.1.product_name', 'Café & Chá')
            ->assertJsonPath('data.1.sale_price', '12.3400')
            ->assertJsonPath('data.1.quantity', 1)
            ->assertJsonPath('meta.toggles.show_price', true)
            ->assertJsonPath('meta.toggles.show_barcode', true)
            ->assertJsonPath('meta.layout.label_width_mm', '50.0000')
            ->assertJsonPath('meta.layout.label_height_mm', '30.0000');
    }

    public function test_legacy_print_settings_keep_the_exact_four_key_contract(): void
    {
        $context = $this->ownerContext();

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/catalog/print-settings')
            ->assertOk()
            ->assertExactJson(['data' => [
                'label_width_mm' => 50,
                'label_height_mm' => 30,
                'show_price' => true,
                'show_barcode' => true,
            ]]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/catalog/print-settings', [
                'label_width_mm' => '55',
                'label_height_mm' => '32',
                'show_price' => '0',
                'show_barcode' => '1',
            ])
            ->assertOk()
            ->assertExactJson(['data' => [
                'label_width_mm' => 55,
                'label_height_mm' => 32,
                'show_price' => false,
                'show_barcode' => true,
            ]]);

        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'product_ids' => [$product->id],
            ])
            ->assertOk()
            ->assertJsonPath('meta.layout.label_width_mm', '55.0000')
            ->assertJsonPath('meta.layout.label_height_mm', '32.0000')
            ->assertJsonPath('meta.toggles.show_price', false)
            ->assertJsonPath('meta.toggles.show_barcode', true);
    }

    public function test_legacy_product_ids_request_keeps_its_data_fields_and_adds_the_resolved_snapshot(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], [
            'name' => 'Produto legado',
            'sku' => 'LEG-1',
            'barcode' => '000123',
            'sale_price' => '8.5000',
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', ['product_ids' => [$product->id, $product->id]])
            ->assertOk()
            ->assertExactJsonStructure([
                'data' => [['id', 'name', 'sku', 'barcode', 'sale_price']],
                'meta' => ['layout', 'toggles', 'compatibility_fallback', 'total_copies', 'output_page_height_mm'],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.barcode', '000123')
            ->assertJsonPath('data.0.sale_price', '8.5000')
            ->assertJsonPath('meta.compatibility_fallback', true);
    }

    public function test_foreign_archived_and_unknown_product_lines_are_rejected_without_a_subset(): void
    {
        $context = $this->ownerContext();
        $valid = $this->product($context['company'], ['barcode' => 'A123']);
        $archived = $this->product($context['company'], ['status' => 'archived', 'barcode' => 'A124']);
        $foreignCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreign = $this->product($foreignCompany, ['barcode' => 'A125']);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [
                    ['product_id' => $valid->id, 'quantity' => 1],
                    ['product_id' => $foreign->id, 'quantity' => 1],
                    ['product_id' => $archived->id, 'quantity' => 1],
                    ['product_id' => 99999999, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.1.product_id',
                'items.2.product_id',
                'items.3.product_id',
            ]);
    }

    public function test_selection_rejects_boolean_and_fractional_identifiers_or_quantities(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => true, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_id');

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $product->id, 'quantity' => 1.5]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $rawJson = json_encode([
            'items' => [['product_id' => $product->id, 'quantity' => 1.0]],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $this->call('POST', '/api/v1/erp/catalog/product-labels', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPMONI_INGRESS_KEY' => 'test-key',
            'HTTP_X_OPMONI_CLIENT_IP' => '127.0.0.1',
            'HTTP_X_OPMONI_CLIENT_PROTO' => 'http',
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_X_COMPANY_ID' => (string) $context['company']->id,
            'HTTP_X_CSRF_TOKEN' => $this->csrf(),
        ], $rawJson)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'layout_id' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout_id');
    }

    public function test_variant_requires_its_own_barcode_instead_of_parent_barcode_or_sku(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'PARENT-1']);
        $variant = ErpProductVariant::factory()->create([
            'erp_product_id' => $product->id,
            'sku' => 'VAR-SKU',
            'barcode' => null,
            'barcode_type' => null,
        ]);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.barcode');
    }

    public function test_invalid_saved_symbology_and_barcode_are_reported_only_when_selected_for_output(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Produto com código antigo',
                'erp_unit_id' => $unit->id,
                'barcode' => '4006381333932',
                'barcode_type' => 'EAN13',
            ])
            ->assertCreated()
            ->assertJsonPath('data.barcode', '4006381333932')
            ->assertJsonPath('data.barcode_type', 'EAN13');
        $productId = ErpProduct::query()->value('id');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.barcode');
    }

    public function test_http_product_and_variant_barcode_writes_preserve_valid_edge_spaces(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Source spaces',
                'erp_unit_id' => $unit->id,
                'barcode' => ' ABC-1 ',
                'barcode_type' => 'C128',
                'variants' => [['name' => 'Source C39', 'barcode' => ' A-02 ', 'barcode_type' => 'C39']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.barcode', ' ABC-1 ')
            ->assertJsonPath('data.variants.0.barcode', ' A-02 ')
            ->json('data');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product['id'], [
                'barcode' => ' 000123 ',
                'variants' => [['id' => $product['variants'][0]['id'], 'name' => 'Source C39', 'barcode' => ' A-03 ']],
            ])
            ->assertOk()
            ->assertJsonPath('data.barcode', ' 000123 ')
            ->assertJsonPath('data.variants.0.barcode', ' A-03 ');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', ['items' => [
                ['product_id' => $product['id'], 'quantity' => 1],
                ['product_id' => $product['id'], 'variant_id' => $product['variants'][0]['id'], 'quantity' => 3],
            ]])
            ->assertOk()
            ->assertJsonPath('data.0.barcode', ' 000123 ')
            ->assertJsonPath('data.1.barcode', ' A-03 ')
            ->assertJsonPath('data.1.barcode_type', 'C39');
    }

    public function test_legacy_duplicate_selection_reports_the_invalid_original_field_index(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $foreignCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreign = $this->product($foreignCompany, ['barcode' => 'B-200']);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', ['product_ids' => [$product->id, $product->id, $foreign->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_ids.2')
            ->assertJsonMissingPath('errors.product_ids.1')
            ->assertJsonMissingPath('data');
    }

    public function test_product_defaults_to_code_128_and_variant_format_can_inherit_or_override(): void
    {
        $context = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Produto C128',
                'erp_unit_id' => $unit->id,
                'barcode' => '000123',
                'variants' => [['name' => 'Variante própria', 'barcode' => 'ABC-1']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.barcode_type', 'C128')
            ->assertJsonPath('data.variants.0.barcode_type', null)
            ->json('data');
        $variantId = $product['variants'][0]['id'];

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product['id'], [
                'variants' => [['id' => $variantId, 'name' => 'Variante própria', 'barcode_type' => 'C39']],
            ])
            ->assertOk()
            ->assertJsonPath('data.variants.0.barcode_type', 'C39');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product['id'], ['barcode_type' => 'QR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('barcode_type');
    }

    public function test_foreign_layout_profile_and_variant_identifiers_are_company_scoped(): void
    {
        $context = $this->ownerContext();
        $ownProduct = $this->product($context['company'], ['barcode' => 'A-100']);
        $ownLayout = ErpCatalogPrintLayout::factory()->create(['erp_company_id' => $context['company']->id]);
        $ownProfile = ErpCatalogPrinterProfile::factory()->create(['erp_company_id' => $context['company']->id]);
        $foreignCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignProduct = $this->product($foreignCompany, ['barcode' => 'B-200']);
        $foreignVariant = ErpProductVariant::factory()->create([
            'erp_product_id' => $foreignProduct->id,
            'barcode' => 'C-300',
        ]);
        $foreignLayout = ErpCatalogPrintLayout::factory()->create(['erp_company_id' => $foreignCompany->id]);
        $foreignProfile = ErpCatalogPrinterProfile::factory()->create(['erp_company_id' => $foreignCompany->id]);
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/catalog/label-layouts/'.$foreignLayout->id)
            ->assertNotFound();
        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/catalog/printer-profiles/'.$foreignProfile->id)
            ->assertNotFound();
        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $ownProduct->id, 'variant_id' => $foreignVariant->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.variant_id');
        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $ownProduct->id, 'quantity' => 1]],
                'layout_id' => $foreignLayout->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout_id');

        $this->assertSame((int) $context['company']->id, (int) $ownLayout->fresh()->erp_company_id);
        $this->assertSame((int) $context['company']->id, (int) $ownProfile->fresh()->erp_company_id);
    }

    public function test_viewer_can_read_print_configuration_but_cannot_write_or_generate_output(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $layout = ErpCatalogPrintLayout::factory()->create(['erp_company_id' => $context['company']->id, 'is_default' => true]);
        $profile = ErpCatalogPrinterProfile::factory()->create(['erp_company_id' => $context['company']->id]);
        ErpCompanyMembership::query()
            ->where('erp_company_id', $context['company']->id)
            ->where('user_id', $context['user']->id)
            ->update(['role' => 'viewer']);

        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/print-settings')->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/label-layouts')->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/label-layouts/'.$layout->id)->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/printer-profiles')->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/printer-profiles/'.$profile->id)->assertOk();

        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];
        $this->withHeaders($headers)->patchJson('/api/v1/erp/catalog/print-settings', [
            'label_width_mm' => 60,
            'label_height_mm' => 30,
            'show_price' => true,
            'show_barcode' => true,
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/label-layouts', $this->layoutPayload('Negado'))->assertForbidden();
        $this->withHeaders($headers)->patchJson('/api/v1/erp/catalog/label-layouts/'.$layout->id.'/default')->assertForbidden();
        $this->withHeaders($headers)->deleteJson('/api/v1/erp/catalog/label-layouts/'.$layout->id)->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/printer-profiles', [
            'name' => 'Negado',
            'capability_profile' => 'default',
        ])->assertForbidden();
        $this->withHeaders($headers)->patchJson('/api/v1/erp/catalog/printer-profiles/'.$profile->id, ['name' => 'Negado'])->assertForbidden();
        $this->withHeaders($headers)->deleteJson('/api/v1/erp/catalog/printer-profiles/'.$profile->id)->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/product-labels', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->assertSame('active', $layout->fresh()->status);
        $this->assertTrue($layout->fresh()->is_default);
        $this->assertSame('active', $profile->fresh()->status);
        $this->assertSame(1, ErpCatalogPrintLayout::query()->where('erp_company_id', $context['company']->id)->count());
        $this->assertSame(1, ErpCatalogPrinterProfile::query()->where('erp_company_id', $context['company']->id)->count());
    }

    public function test_delegated_exact_read_grants_do_not_authorize_any_configuration_or_output_write(): void
    {
        $context = $this->delegatedContext();
        $layout = ErpCatalogPrintLayout::factory()->create(['erp_company_id' => $context['company']->id]);
        $profile = ErpCatalogPrinterProfile::factory()->create(['erp_company_id' => $context['company']->id]);
        $product = $this->product($context['company'], ['barcode' => 'A-100']);

        $identity = $this->withHeaders($context['headers'])->getJson('/api/v1/me')->assertOk();
        $permissions = $identity->json('current_company.permissions');
        $this->assertCount(5, $permissions);
        $this->assertEqualsCanonicalizing([
            'erp.catalog.print-settings.show',
            'erp.catalog.label-layouts.index',
            'erp.catalog.label-layouts.show',
            'erp.catalog.printer-profiles.index',
            'erp.catalog.printer-profiles.show',
        ], $permissions);

        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/print-settings')->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/label-layouts')->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/label-layouts/'.$layout->id)->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/printer-profiles')->assertOk();
        $this->withHeaders($context['headers'])->getJson('/api/v1/erp/catalog/printer-profiles/'.$profile->id)->assertOk();

        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];
        $this->withHeaders($headers)->patchJson('/api/v1/erp/catalog/print-settings', [
            'label_width_mm' => 60,
            'label_height_mm' => 30,
            'show_price' => true,
            'show_barcode' => true,
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/label-layouts', $this->layoutPayload('Negado'))->assertForbidden();
        $this->withHeaders($headers)->patchJson('/api/v1/erp/catalog/label-layouts/'.$layout->id.'/default')->assertForbidden();
        $this->withHeaders($headers)->deleteJson('/api/v1/erp/catalog/label-layouts/'.$layout->id)->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/printer-profiles', [
            'name' => 'Negado',
            'capability_profile' => 'default',
        ])->assertForbidden();
        $this->withHeaders($headers)->patchJson('/api/v1/erp/catalog/printer-profiles/'.$profile->id, ['name' => 'Negado'])->assertForbidden();
        $this->withHeaders($headers)->deleteJson('/api/v1/erp/catalog/printer-profiles/'.$profile->id)->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/product-labels', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->assertSame('active', $layout->fresh()->status);
        $this->assertSame('active', $profile->fresh()->status);
        $this->assertSame(1, ErpCatalogPrintLayout::query()->where('erp_company_id', $context['company']->id)->count());
        $this->assertSame(1, ErpCatalogPrinterProfile::query()->where('erp_company_id', $context['company']->id)->count());
    }

    public function test_form_encoded_legacy_dimensions_remain_integer_strings_for_label_resolution(): void
    {
        $context = $this->ownerContext();
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf(), 'Accept' => 'application/json'];

        $this->patch('/api/v1/erp/catalog/print-settings', [
            'label_width_mm' => '58',
            'label_height_mm' => '31',
            'show_price' => '0',
            'show_barcode' => '1',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.label_width_mm', 58)
            ->assertJsonPath('data.label_height_mm', 31)
            ->assertJsonPath('data.show_price', false)
            ->assertJsonPath('data.show_barcode', true);

        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', ['product_ids' => [$product->id]])
            ->assertOk()
            ->assertJsonPath('meta.layout.label_width_mm', '58.0000')
            ->assertJsonPath('meta.layout.label_height_mm', '31.0000')
            ->assertJsonPath('meta.toggles.show_price', false);
    }

    public function test_selection_rejects_251_copies_and_continuous_output_over_2000_mm(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];

        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/product-labels', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 125],
                ['product_id' => $product->id, 'quantity' => 126],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.quantity');

        $continuous = $this->layoutPayload('Contínua longa');
        $continuous['label_height_mm'] = '200.0000';
        $continuous['paper_width_mm'] = '100.0000';
        $continuous['paper_height_mm'] = null;
        $continuous['top_margin_mm'] = '0.0000';
        $continuous['columns_per_row'] = 1;
        $continuous['labels_per_sheet'] = null;
        $continuous['is_continuous'] = true;
        $layout = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/label-layouts', $continuous)
            ->assertCreated()
            ->json('data');

        $this->withHeaders($headers)->postJson('/api/v1/erp/catalog/product-labels', [
            'items' => [['product_id' => $product->id, 'quantity' => 11]],
            'layout_id' => $layout['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('layout_id');
    }

    public function test_label_output_uses_company_settings_refetched_after_company_lock(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $context['company']->forceFill(['operating_settings' => [
            'catalog_print' => [
                'label_width_mm' => 50,
                'label_height_mm' => 30,
                'show_price' => true,
                'show_barcode' => true,
            ],
        ]])->save();
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $context): void {
            $normalized = strtolower($query->sql);
            if ($changed || ! str_contains($normalized, 'select * from "erp_companies"')
                || ! in_array((int) $context['company']->id, array_map(intval(...), $query->bindings), true)) {
                return;
            }

            $changed = true;
            DB::table('erp_companies')
                ->where('id', $context['company']->id)
                ->update(['operating_settings' => json_encode(['catalog_print' => [
                    'label_width_mm' => 62,
                    'label_height_mm' => 34,
                    'show_price' => false,
                    'show_barcode' => false,
                ]], JSON_THROW_ON_ERROR)]);
        });

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('meta.layout.label_width_mm', '62.0000')
            ->assertJsonPath('meta.layout.label_height_mm', '34.0000')
            ->assertJsonPath('meta.toggles.show_price', false)
            ->assertJsonPath('meta.toggles.show_barcode', false);

        $this->assertTrue($changed);
    }

    public function test_default_switch_rolls_back_if_access_session_is_lost_after_the_callback(): void
    {
        $context = $this->ownerContext();
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];
        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/label-layouts', $this->layoutPayload('Padrão original'))
            ->assertCreated()
            ->json('data');
        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/catalog/label-layouts', $this->layoutPayload('Novo padrão'))
            ->assertCreated()
            ->json('data');
        $first = $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/catalog/label-layouts/'.$first['id'].'/default')
            ->assertOk()
            ->json('data');

        $active = true;
        DB::listen(function (QueryExecuted $query) use (&$active): void {
            if (! $active || ! str_starts_with(strtolower(ltrim($query->sql)), 'update "erp_catalog_print_layouts"')) {
                return;
            }

            $active = false;
            request()->attributes->get('access.session_lock')->forceRelease();
        });

        $this->withHeaders($headers)->patchJson('/api/v1/erp/catalog/label-layouts/'.$second['id'].'/default')->assertStatus(503);
        $this->assertTrue($first['is_default']);
        $this->assertTrue(ErpCatalogPrintLayout::query()->whereKey($first['id'])->value('is_default'));
        $this->assertFalse(ErpCatalogPrintLayout::query()->whereKey($second['id'])->value('is_default'));
        $this->assertSame(1, ErpCatalogPrintLayout::query()->where('erp_company_id', $context['company']->id)->where('is_default', true)->count());
    }

    public function test_layout_dimensions_are_lossless_and_default_resolution_is_company_scoped(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $payload = $this->layoutPayload('Caixa fracionada');
        $payload['label_width_mm'] = '45.5000';
        $payload['label_height_mm'] = '29.2500';
        $payload['paper_width_mm'] = '100.0000';
        $payload['paper_height_mm'] = '100.0000';
        $payload['columns_per_row'] = 2;
        $payload['labels_per_sheet'] = 4;

        $layout = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/label-layouts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.label_width_mm', '45.5000')
            ->assertJsonPath('data.label_height_mm', '29.2500')
            ->assertJsonPath('data.columns_per_row', 2)
            ->json('data');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/catalog/label-layouts/'.$layout['id'].'/default')
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('meta.layout.id', $layout['id'])
            ->assertJsonPath('meta.layout.label_width_mm', '45.5000')
            ->assertJsonPath('meta.compatibility_fallback', false);

        $this->assertSame(1, ErpCatalogPrintLayout::query()->where('erp_company_id', $context['company']->id)->where('is_default', true)->count());
    }

    public function test_explicit_unavailable_layout_is_rejected_and_archiving_default_does_not_select_another(): void
    {
        $context = $this->ownerContext();
        $product = $this->product($context['company'], ['barcode' => 'A-100']);
        $default = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/label-layouts', $this->layoutPayload('Padrão'))
            ->assertCreated()
            ->json('data');
        $other = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/label-layouts', $this->layoutPayload('Outra'))
            ->assertCreated()
            ->json('data');
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/catalog/label-layouts/'.$default['id'].'/default')
            ->assertOk();
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->deleteJson('/api/v1/erp/catalog/label-layouts/'.$default['id'])
            ->assertOk()
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.status', 'archived');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'layout_id' => $default['id'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout_id');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/product-labels', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('meta.layout.id', null)
            ->assertJsonPath('meta.compatibility_fallback', true);

        $this->assertSame(0, ErpCatalogPrintLayout::query()->whereKey($other['id'])->where('is_default', true)->count());
    }

    public function test_layout_rejects_excess_precision_and_nonfitting_sheet_without_persistence(): void
    {
        $context = $this->ownerContext();
        $invalidPrecision = $this->layoutPayload('Precisão inválida');
        $invalidPrecision['label_width_mm'] = '20.00001';
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/label-layouts', $invalidPrecision)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_width_mm');

        $nonfitting = $this->layoutPayload('Folha apertada');
        $nonfitting['paper_width_mm'] = '50.0000';
        $nonfitting['columns_per_row'] = 2;
        $nonfitting['labels_per_sheet'] = 4;
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/label-layouts', $nonfitting)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paper_width_mm');

        $this->assertDatabaseCount('erp_catalog_print_layouts', 0);
    }

    public function test_printer_profile_stores_only_allowlisted_descriptive_metadata_and_archives(): void
    {
        $context = $this->ownerContext();
        $profile = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/printer-profiles', [
                'name' => 'Balcão',
                'capability_profile' => 'TEP-200M',
            ])
            ->assertCreated()
            ->assertJsonPath('data.capability_profile', 'TEP-200M')
            ->json('data');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/catalog/printer-profiles', [
                'name' => 'Acesso remoto',
                'capability_profile' => 'simple',
                'ip_address' => '192.0.2.1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ip_address');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->deleteJson('/api/v1/erp/catalog/printer-profiles/'.$profile['id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
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

    /** @return array{user: User, company: ErpCompany, headers: array<string, string>} */
    private function delegatedContext(): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $office = Account::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $office->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
        $company = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
            'permission_allowlist' => [
                'erp.catalog.print-settings.show',
                'erp.catalog.label-layouts.index',
                'erp.catalog.label-layouts.show',
                'erp.catalog.printer-profiles.index',
                'erp.catalog.printer-profiles.show',
            ],
        ]);
        $this->login($user);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('current_company.access_kind', 'delegated');

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

    /** @param array<string, mixed> $overrides */
    private function product(ErpCompany $company, array $overrides = []): ErpProduct
    {
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);

        return ErpProduct::factory()->create([
            'erp_company_id' => $company->id,
            'erp_unit_id' => $unit->id,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function layoutPayload(string $name): array
    {
        return [
            'name' => $name,
            'description' => 'Layout de teste',
            'label_width_mm' => '40.0000',
            'label_height_mm' => '25.0000',
            'paper_width_mm' => '120.0000',
            'paper_height_mm' => '100.0000',
            'top_margin_mm' => '2.0000',
            'left_margin_mm' => '2.0000',
            'row_spacing_mm' => '1.0000',
            'column_spacing_mm' => '1.0000',
            'columns_per_row' => 2,
            'labels_per_sheet' => 4,
            'is_continuous' => false,
        ];
    }
}
