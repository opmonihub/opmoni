<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpQuote;
use App\Models\Erp\Sales\ErpQuoteLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErpProductTaxProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_upgrade_never_adopts_or_rewrites_existing_tax_values_and_snapshot_shapes(): void
    {
        $this->assertTrue(Schema::hasTable('erp_product_tax_profiles'));
        $migration = require database_path('migrations/2026_10_24_080000_create_erp_product_tax_profiles.php');
        $migration->down();
        $company = ErpCompany::factory()->create(['operating_settings' => ['regime' => 3, 'cst_pis_padrao' => '01']]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $company->id, 'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $company->id])->id, 'icms_cst' => null, 'pis_cst' => '01', 'pis_rate' => '0.0000']);
        $before = DB::table('erp_products')->where('id', $product->id)->sole();
        $quote = ErpQuote::query()->create(['erp_company_id' => $company->id, 'erp_establishment_id' => ErpEstablishment::factory()->create(['erp_company_id' => $company->id])->id, 'quote_no' => 'Legacy-1', 'status' => 'draft', 'total_amount' => '10.0000', 'currency' => 'BRL', 'commercial_snapshot' => ['version' => 'commercial-v1', 'legacy' => true]]);
        $line = ErpQuoteLine::factory()->forDocument($quote)->create(['product_snapshot' => ['version' => 'commercial-v1', 'pis_cst' => '01', 'icms_cst' => null]]);
        $beforeSnapshot = DB::table('erp_quote_lines')->where('id', $line->id)->sole();
        $snapshotColumns = Schema::getColumns('erp_sale_lines');
        $migration->up();
        $after = (array) DB::table('erp_products')->where('id', $product->id)->sole();
        unset($after['erp_product_tax_profile_id'], $after['tax_field_intents']);
        $this->assertEquals((array) $before, $after);
        $this->assertNull($product->fresh()->erp_product_tax_profile_id);
        $this->assertNull($product->fresh()->tax_field_intents);
        $this->assertSame($snapshotColumns, Schema::getColumns('erp_sale_lines'));
        $this->assertEquals($beforeSnapshot, DB::table('erp_quote_lines')->where('id', $line->id)->sole());
        $this->assertSame(['version' => 'commercial-v1', 'legacy' => true], $quote->fresh()->commercial_snapshot);
        $this->assertDatabaseCount('erp_company_product_tax_defaults', 0);
        $this->assertDatabaseCount('erp_product_tax_profiles', 0);
    }

    public function test_foreign_product_profile_pointer_is_rejected_by_database(): void
    {
        $company = ErpCompany::factory()->create();
        $foreign = ErpCompany::factory()->create();
        $profile = DB::table('erp_product_tax_profiles')->insertGetId(['erp_company_id' => $foreign->id, 'name' => 'Foreign', 'status' => 'active']);
        $product = ErpProduct::factory()->create(['erp_company_id' => $company->id, 'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $company->id])->id]);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');
        DB::table('erp_products')->where('id', $product->id)->update(['erp_product_tax_profile_id' => $profile]);
    }

    public function test_foreign_company_default_pointer_is_rejected_by_database(): void
    {
        $company = ErpCompany::factory()->create();
        $foreign = ErpCompany::factory()->create();
        $profile = DB::table('erp_product_tax_profiles')->insertGetId(['erp_company_id' => $foreign->id, 'name' => 'Foreign', 'status' => 'active']);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');
        DB::table('erp_company_product_tax_defaults')->insert(['erp_company_id' => $company->id, 'erp_product_tax_profile_id' => $profile, 'revision' => 1]);
    }

    public function test_revisions_cannot_be_updated_through_query_builder(): void
    {
        $company = ErpCompany::factory()->create();
        $profile = DB::table('erp_product_tax_profiles')->insertGetId(['erp_company_id' => $company->id, 'name' => 'Reviewed', 'status' => 'active']);
        $revision = DB::table('erp_product_tax_profile_revisions')->insertGetId(['erp_company_id' => $company->id, 'erp_product_tax_profile_id' => $profile, 'revision' => 1, 'name' => 'Reviewed', 'status' => 'active', 'fields' => '{}', 'applicability' => '{}', 'actor_user_id' => 1, 'access_kind' => 'direct']);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/immutable/i');
        DB::table('erp_product_tax_profile_revisions')->where('id', $revision)->update(['fields' => '{"pis_cst":"02"}']);
    }

    public function test_revisions_cannot_be_deleted_through_query_builder(): void
    {
        $company = ErpCompany::factory()->create();
        $profile = DB::table('erp_product_tax_profiles')->insertGetId(['erp_company_id' => $company->id, 'name' => 'Reviewed', 'status' => 'active']);
        $revision = DB::table('erp_product_tax_profile_revisions')->insertGetId(['erp_company_id' => $company->id, 'erp_product_tax_profile_id' => $profile, 'revision' => 1, 'name' => 'Reviewed', 'status' => 'active', 'fields' => '{}', 'applicability' => '{}', 'actor_user_id' => 1, 'access_kind' => 'direct']);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/immutable/i');
        DB::table('erp_product_tax_profile_revisions')->where('id', $revision)->delete();
    }
}
