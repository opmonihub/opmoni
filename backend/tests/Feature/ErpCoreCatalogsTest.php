<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCarrier;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\ErpVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ErpCoreCatalogsTest extends TestCase
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
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
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

    public function test_contact_crud_search_and_archive(): void
    {
        $ctx = $this->ownerContext();
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/contacts', [
                'name' => 'Cliente Alpha',
                'is_customer' => true,
                'tax_id' => '39053344705',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cliente Alpha');

        $id = ErpContact::query()->value('id');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/contacts?search=Alpha')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->deleteJson('/api/v1/erp/contacts/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_same_tax_id_does_not_mutate_office_client(): void
    {
        $ctx = $this->ownerContext();
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->id, 'tax_id' => self::VALID_CNPJ, 'name' => 'Escritório Original']);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/contacts', [
                'name' => 'Cliente Comercial',
                'tax_id' => self::VALID_CNPJ,
                'is_customer' => true,
            ])
            ->assertCreated();

        $client->refresh();
        $this->assertSame('Escritório Original', $client->name);
    }

    public function test_product_rejects_foreign_unit_and_preserves_decimal_price(): void
    {
        $ctx = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $otherCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignUnit = ErpUnit::factory()->create(['erp_company_id' => $otherCompany->id]);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Produto',
                'erp_unit_id' => $foreignUnit->id,
                'sale_price' => '10.5000',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.erp_unit_id.0', 'Registro inválido para esta empresa');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/products', [
                'name' => 'Produto OK',
                'erp_unit_id' => $unit->id,
                'sale_price' => '10.5000',
                'warranty_months' => 12,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sale_price', '10.5000');

        $productId = ErpProduct::query()->value('id');
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$productId, ['sale_price' => '99.9900'])
            ->assertOk()
            ->assertJsonPath('data.sale_price', '99.9900');
    }

    public function test_foreign_resource_returns_404(): void
    {
        $ctx = $this->ownerContext();
        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreign = ErpContact::factory()->create(['erp_company_id' => $other->id]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/contacts/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_vehicle_rejects_foreign_carrier(): void
    {
        $ctx = $this->ownerContext();
        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignCarrier = ErpCarrier::factory()->create(['erp_company_id' => $other->id]);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/vehicles', [
                'plate' => 'ABC-1D23',
                'erp_carrier_id' => $foreignCarrier->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.erp_carrier_id.0', 'Registro inválido para esta empresa');
    }

    public function test_product_import_preview_and_confirm(): void
    {
        $ctx = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $csv = "name,sku,sale_price\nItem Import,SKU-1,15.50\n";
        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $preview = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->post('/api/v1/erp/catalog/import/products/preview', ['file' => $file])
            ->assertOk()
            ->json('data');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->withHeader('Idempotency-Key', 'core-catalog-csv-import')
            ->postJson('/api/v1/erp/catalog/import/products/confirm', [
                'token' => $preview['token'],
                'erp_unit_id' => $unit->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertSame(1, ErpProduct::query()->where('sku', 'SKU-1')->count());
    }

    public function test_archived_product_stays_readable(): void
    {
        $ctx = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_unit_id' => $unit->id,
            'sale_price' => '5.0000',
        ]);
        $originalPrice = $product->sale_price;

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->deleteJson('/api/v1/erp/products/'.$product->id)
            ->assertOk();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $product->refresh();
        $this->assertSame((string) $originalPrice, (string) $product->sale_price);
    }

    public function test_product_update_with_invalid_unit_leaves_fields_unchanged(): void
    {
        $ctx = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignUnit = ErpUnit::factory()->create(['erp_company_id' => $other->id]);
        $brand = ErpBrand::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Marca A']);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_unit_id' => $unit->id,
            'erp_brand_id' => $brand->id,
            'name' => 'Antes',
        ]);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->patchJson('/api/v1/erp/products/'.$product->id, [
                'name' => 'Depois',
                'erp_unit_id' => $foreignUnit->id,
            ])
            ->assertStatus(422);

        $product->refresh();
        $this->assertSame('Antes', $product->name);
        $this->assertSame($unit->id, $product->erp_unit_id);
    }

    public function test_catalog_name_sort_is_validated_stable_and_applied_before_pagination(): void
    {
        $ctx = $this->ownerContext();
        $first = ErpContact::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Alpha']);
        $second = ErpContact::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Alpha']);
        ErpContact::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Zulu']);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/contacts?sort=name&direction=desc&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Zulu');
        $pageTwo = $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/contacts?sort=name&direction=desc&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha');

        $this->assertSame((int) $second->id, (int) $pageTwo->json('data.0.id'));
        $this->assertNotSame((int) $first->id, (int) $pageTwo->json('data.0.id'));
    }

    public function test_brand_name_sort_is_validated_stable_and_applied_before_pagination(): void
    {
        $ctx = $this->ownerContext();
        $first = ErpBrand::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Alpha']);
        $second = ErpBrand::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Alpha']);
        ErpBrand::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Zulu']);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/brands?sort=name&direction=desc&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Zulu');
        $pageTwo = $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/brands?sort=name&direction=desc&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha');

        $this->assertSame((int) $second->id, (int) $pageTwo->json('data.0.id'));
        $this->assertNotSame((int) $first->id, (int) $pageTwo->json('data.0.id'));
    }

    public function test_catalog_identity_sort_fields_cover_carriers_vehicles_and_operation_natures(): void
    {
        $ctx = $this->ownerContext();
        ErpCarrier::factory()->create(['erp_company_id' => $ctx['company']->id, 'legal_name' => 'Alpha']);
        ErpCarrier::factory()->create(['erp_company_id' => $ctx['company']->id, 'legal_name' => 'Zulu']);
        ErpVehicle::factory()->create(['erp_company_id' => $ctx['company']->id, 'plate' => 'AAA1A11']);
        ErpVehicle::factory()->create(['erp_company_id' => $ctx['company']->id, 'plate' => 'ZZZ9Z99']);
        ErpOperationNature::factory()->create(['erp_company_id' => $ctx['company']->id, 'cfop' => '5102']);
        ErpOperationNature::factory()->create(['erp_company_id' => $ctx['company']->id, 'cfop' => '6102']);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/carriers?sort=legal_name&direction=desc&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.legal_name', 'Zulu');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/vehicles?sort=plate&direction=desc&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.plate', 'ZZZ9Z99');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/tax-settings/operation-natures?sort=cfop&direction=desc&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.cfop', '6102');

        ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id, 'actual_name' => 'Alpha', 'short_name' => 'A']);
        ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id, 'actual_name' => 'Zulu', 'short_name' => 'Z']);
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/units?sort=actual_name&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.actual_name', 'Zulu');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/units?sort=short_name&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.short_name', 'Z');
    }

    public function test_catalog_sort_preserves_default_order_envelope_company_scope_and_cfop_default(): void
    {
        $ctx = $this->ownerContext();
        $secondBrand = ErpBrand::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'Second']);
        $firstBrand = ErpBrand::factory()->create(['erp_company_id' => $ctx['company']->id, 'name' => 'First']);
        $foreignCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        ErpCarrier::factory()->create(['erp_company_id' => $ctx['company']->id, 'legal_name' => 'Alpha Local']);
        ErpCarrier::factory()->create(['erp_company_id' => $foreignCompany->id, 'legal_name' => 'Zulu Foreign']);
        $nature = ErpOperationNature::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'cfop' => '5102',
            'description' => 'Default sale',
        ]);
        ErpOperationNature::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'cfop' => '6102',
            'description' => 'Another operation',
        ]);

        $defaultList = $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/brands?per_page=1&page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertSame((int) $secondBrand->id, (int) $defaultList->json('data.0.id'));
        $this->assertNotSame((int) $firstBrand->id, (int) $defaultList->json('data.0.id'));
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/brands?sort=name')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'First');

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/carriers?sort=legal_name&direction=desc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.legal_name', 'Alpha Local');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/tax-settings/operation-natures?sort=cfop&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.cfop', '6102');

        $this->assertSame('5102', $nature->fresh()->cfop);
    }

    public function test_catalog_sort_parameters_reject_unsupported_fields_and_direction_without_sort(): void
    {
        $ctx = $this->ownerContext();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/brands?sort=created_at')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/brands?direction=desc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction');
        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/brands?sort=name&direction=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction');
    }
}
