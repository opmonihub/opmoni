<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpOperationNature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsOperationNatureHarness;
use Tests\TestCase;

class ErpOperationNatureRuleSchemaTest extends TestCase
{
    use BuildsOperationNatureHarness, RefreshDatabase;

    public function test_additive_upgrade_preserves_native_nature_values_and_does_not_backfill_choices_or_exceptions(): void
    {
        $this->assertTrue(Schema::hasTable('erp_operation_nature_cfop_choices'));
        $migration = require database_path('migrations/2026_10_24_100000_add_erp_operation_nature_rules.php');
        $migration->down();
        $nature = ErpOperationNature::factory()->create(['cfop' => '5.102', 'fiscal_metadata' => ['legacy' => ['zero' => 0, 'flag' => false]]]);
        $before = (array) DB::table('erp_operation_natures')->where('id', $nature->id)->sole();
        $snapshotColumns = Schema::getColumns('erp_sale_lines');
        $migration->up();
        $after = (array) DB::table('erp_operation_natures')->where('id', $nature->id)->sole();
        $this->assertSame(1, $after['configuration_revision']);
        $this->assertNull($after['cfop_reference_version']);
        unset($after['configuration_revision'], $after['cfop_reference_version']);
        $this->assertSame($before, $after);
        $this->assertSame($snapshotColumns, Schema::getColumns('erp_sale_lines'));
        $this->assertDatabaseCount('erp_operation_nature_cfop_choices', 0);
        $this->assertDatabaseCount('erp_operation_nature_product_exceptions', 0);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRelationships(): iterable
    {
        yield 'foreign nature' => ['nature'];
        yield 'foreign product' => ['product'];
        yield 'different nature choice' => ['choice'];
    }

    #[DataProvider('invalidRelationships')]
    public function test_database_rejects_foreign_and_cross_nature_exception_relationships(string $invalid): void
    {
        $company = ErpCompany::factory()->create();
        $foreign = ErpCompany::factory()->create();
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id]);
        $other = ErpOperationNature::factory()->create(['erp_company_id' => $invalid === 'nature' ? $foreign->id : $company->id]);
        $product = $this->natureProduct($invalid === 'product' ? $foreign : $company);
        $choice = DB::table('erp_operation_nature_cfop_choices')->insertGetId(['erp_company_id' => $company->id, 'erp_operation_nature_id' => $invalid === 'choice' ? $other->id : $nature->id, 'cfop' => '5102', 'reference_version' => 'IT 2023.002 v2.10', 'status' => 'active']);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');
        DB::table('erp_operation_nature_product_exceptions')->insert(['erp_company_id' => $company->id, 'erp_operation_nature_id' => $invalid === 'nature' ? $other->id : $nature->id, 'erp_product_id' => $product->id, 'erp_operation_nature_cfop_choice_id' => $choice, 'status' => 'active']);
    }

    public function test_database_rejects_foreign_nature_choice_and_duplicate_active_exception_but_retains_archive_history(): void
    {
        $company = ErpCompany::factory()->create();
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id]);
        $product = $this->natureProduct($company);
        $choice = DB::table('erp_operation_nature_cfop_choices')->insertGetId(['erp_company_id' => $company->id, 'erp_operation_nature_id' => $nature->id, 'cfop' => '5102', 'reference_version' => 'IT 2023.002 v2.10', 'status' => 'active']);
        $row = ['erp_company_id' => $company->id, 'erp_operation_nature_id' => $nature->id, 'erp_product_id' => $product->id, 'erp_operation_nature_cfop_choice_id' => $choice];
        DB::table('erp_operation_nature_product_exceptions')->insert([...$row, 'status' => 'archived']);
        DB::table('erp_operation_nature_product_exceptions')->insert([...$row, 'status' => 'archived']);
        DB::table('erp_operation_nature_product_exceptions')->insert([...$row, 'status' => 'active']);
        $this->assertDatabaseCount('erp_operation_nature_product_exceptions', 3);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique/i');
        DB::table('erp_operation_nature_product_exceptions')->insert([...$row, 'status' => 'active']);
    }

    public function test_database_rejects_choice_owned_by_another_company_nature(): void
    {
        $nature = ErpOperationNature::factory()->create();
        $foreign = ErpCompany::factory()->create();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');
        DB::table('erp_operation_nature_cfop_choices')->insert(['erp_company_id' => $foreign->id, 'erp_operation_nature_id' => $nature->id, 'cfop' => '5102', 'reference_version' => 'IT 2023.002 v2.10', 'status' => 'active']);
    }
}
