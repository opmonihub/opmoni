<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpProductVariantReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = '/api/v1/erp/product-variants';

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

    /** @return array{company: ErpCompany, user: User} */
    private function ownerContext(): array
    {
        $company = $this->company();
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->login($user, $company);

        return compact('company', 'user');
    }

    private function company(): ErpCompany
    {
        $company = ErpCompany::factory()->create();
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'active',
        ]);

        return $company;
    }

    private function login(User $user, ErpCompany $company): void
    {
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk();
        $this->withHeaders([
            'X-Company-Id' => (string) $company->id,
            'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'),
        ]);
    }

    /** @return array{product: ErpProduct, variant: ErpProductVariant} */
    private function variant(ErpCompany $company, array $product = [], array $variant = []): array
    {
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $record = ErpProduct::factory()->create([
            'erp_company_id' => $company->id,
            'erp_unit_id' => $unit->id,
            ...$product,
        ]);
        $choice = ErpProductVariant::factory()->create([
            'erp_product_id' => $record->id,
            ...$variant,
        ]);

        return ['product' => $record, 'variant' => $choice];
    }

    public function test_index_searches_variant_and_parent_identity_with_bounded_pagination(): void
    {
        ['company' => $company] = $this->ownerContext();
        $first = $this->variant($company, ['name' => 'Café torrado', 'sku' => 'PARENT-COFFEE', 'barcode' => '1234567890123'], ['name' => 'Pacote 500 g', 'sku' => 'CAFE-500-A']);
        $second = $this->variant($company, ['name' => 'Café especial'], ['name' => 'Pacote 500 g', 'sku' => 'CAFE-500-B']);
        $this->variant($company, ['name' => 'Chá verde'], ['name' => 'Caixa', 'sku' => 'CHA-01']);

        $this->getJson(self::INDEX.'?usage=sales&search=500&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first['variant']->id)
            ->assertJsonPath('data.0.product.id', $first['product']->id)
            ->assertJsonPath('data.0.product.name', 'Café torrado')
            ->assertJsonMissingPath('data.0.variants');

        $this->getJson(self::INDEX.'?usage=sales&search=CAFE-500&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.id', $second['variant']->id);

        $this->getJson(self::INDEX.'?usage=sales&search=torrado')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first['variant']->id);

        $this->getJson(self::INDEX.'?usage=sales&search=PARENT-COFFEE')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first['variant']->id);

        $this->getJson(self::INDEX.'?usage=sales&search=1234567890123')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first['variant']->id);
    }

    public function test_index_filters_compatible_active_rows_before_pagination(): void
    {
        ['company' => $company] = $this->ownerContext();
        $service = $this->variant($company, ['kind' => 'service', 'track_stock' => false, 'name' => 'Serviço Café', 'sku' => 'PARENT-CAFE-SERVICE'], ['name' => 'Unidade', 'sku' => 'CAFE-SERVICE']);
        $goods = $this->variant($company, ['kind' => 'goods', 'track_stock' => true, 'name' => 'Mercadoria Café', 'sku' => 'PARENT-CAFE-STOCK'], ['name' => 'Pacote', 'sku' => 'CAFE-STOCK']);
        $nonStockGoods = $this->variant($company, ['kind' => 'goods', 'track_stock' => false, 'name' => 'Digital']);
        $archivedVariant = $this->variant($company, ['kind' => 'goods', 'track_stock' => true], ['status' => 'archived']);
        $archivedProduct = $this->variant($company, ['kind' => 'goods', 'track_stock' => true, 'status' => 'archived']);

        $this->getJson(self::INDEX.'?usage=sales&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.*.id', [$service['variant']->id, $goods['variant']->id, $nonStockGoods['variant']->id]);

        $this->getJson(self::INDEX.'?usage=purchase&search=CAFE&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('data.*.id', [$goods['variant']->id]);

        $this->getJson(self::INDEX.'?usage=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usage');
    }

    public function test_search_treats_sql_wildcards_as_literal_input(): void
    {
        ['company' => $company] = $this->ownerContext();
        $literal = $this->variant($company, ['name' => 'Item 50%_A']);
        $this->variant($company, ['name' => 'Item 50xxA']);

        $this->getJson(self::INDEX.'?usage=sales&search=50%25_A')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $literal['variant']->id);
    }

    public function test_selected_hydration_reads_same_company_archived_rows_but_hides_foreign_rows(): void
    {
        ['company' => $company] = $this->ownerContext();
        $archived = $this->variant($company, ['name' => 'Produto histórico', 'status' => 'archived'], ['name' => 'Unidade antiga', 'status' => 'archived']);
        $foreignCompany = $this->company();
        $foreign = $this->variant($foreignCompany, ['name' => 'Produto alheio']);

        $this->getJson(self::INDEX.'/'.$archived['variant']->id)
            ->assertOk()
            ->assertJsonPath('data.id', $archived['variant']->id)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.product.id', $archived['product']->id)
            ->assertJsonPath('data.product.status', 'archived');

        $this->getJson(self::INDEX.'/'.$foreign['variant']->id)->assertNotFound();
    }

    public function test_index_and_selected_hydration_reuse_only_the_products_index_grant(): void
    {
        ['company' => $company, 'user' => $user] = $this->ownerContext();
        $choice = $this->variant($company);
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
        $membership->update(['role' => 'viewer', 'action_permissions' => ['erp.products.index']]);

        $this->getJson(self::INDEX.'?usage=sales')->assertOk();
        $this->getJson(self::INDEX.'/'.$choice['variant']->id)->assertOk();

        $membership->update(['action_permissions' => ['erp.contacts.index']]);
        $this->getJson(self::INDEX.'?usage=sales')->assertForbidden();
        $this->getJson(self::INDEX.'/'.$choice['variant']->id)->assertForbidden();
    }
}
