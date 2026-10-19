<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErpTypedCatalogSetupFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

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

    public function test_customer_group_default_and_product_fiscal_values_round_trip(): void
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create([
            'cnpj' => (string) random_int(90000000000000, 99999999999999),
        ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
        ]);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $headers = ['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')];
        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'Varejo'])
            ->assertCreated()
            ->assertJsonPath('data.percentage_adjustment', '0.0000');

        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $product = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/products', [
                'name' => 'Item com classificação em rascunho',
                'erp_unit_id' => $unit->id,
                'ncm' => '00',
                'icms_cst' => '00',
                'pis_rate' => '0.0001',
                'ibs_cbs_cst' => '999',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ncm', '00')
            ->assertJsonPath('data.icms_cst', '00')
            ->assertJsonPath('data.pis_rate', '0.0001')
            ->assertJsonPath('data.ibs_cbs_cst', '999');

        $migration = require database_path('migrations/2026_10_19_041740_create_erp_customer_groups_table_and_add_product_fiscal_fields.php');
        try {
            $migration->down();
            $this->fail('Typed catalog data must prevent destructive migration rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Restore a compatible application version', $exception->getMessage());
        }

        $this->assertNotNull($product->json('data.id'));
        $this->assertTrue(Schema::hasTable('erp_customer_groups'));
        $this->assertTrue(Schema::hasColumn('erp_contacts', 'erp_customer_group_id'));
        $this->assertTrue(Schema::hasColumn('erp_products', 'ibs_cbs_classification_code'));
    }
}
