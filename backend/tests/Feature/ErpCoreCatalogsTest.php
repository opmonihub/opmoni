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
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
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
}
