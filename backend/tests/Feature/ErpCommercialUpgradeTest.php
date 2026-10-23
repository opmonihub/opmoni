<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErpCommercialUpgradeTest extends TestCase
{
    public function test_upgrade_preserves_legacy_duplicate_children_without_inventing_lines(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Commercial upgrade requires isolated SQLite memory.');
        }
        config(['database.connections.commercial_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'database.default' => 'commercial_upgrade']);
        $migrationPath = database_path('migrations/2026_10_20_145329_add_erp_commercial_lines_and_return_allocations.php');
        $prior = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => strcmp($path, $migrationPath) < 0));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'commercial_upgrade', '--path' => $prior, '--realpath' => true, '--force' => true, '--no-interaction' => true]));
        $company = ErpCompany::factory()->create();
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $base = ['erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id, 'currency' => 'BRL', 'total_amount' => '10.1234'];
        $quote = DB::table('erp_quotes')->insertGetId($base + ['quote_no' => 'legacy', 'status' => 'converted']);
        foreach (['first', 'duplicate'] as $number) {
            $order = DB::table('erp_orders')->insertGetId($base + ['erp_quote_id' => $quote, 'order_no' => $number, 'status' => 'confirmed']);
            foreach (['one', 'two'] as $saleNumber) {
                DB::table('erp_sales')->insert($base + ['erp_order_id' => $order, 'invoice_no' => $number.'-'.$saleNumber, 'status' => 'posted', 'subtotal_amount' => '10.1234', 'discount_amount' => '0', 'tax_amount' => '0', 'fiscal_status' => 'authorized']);
            }
        }
        $before = [];
        foreach (['erp_companies', 'erp_establishments', 'erp_quotes', 'erp_orders', 'erp_sales', 'erp_sale_lines', 'erp_sale_returns', 'erp_purchase_returns', 'erp_payments', 'erp_obligations'] as $table) {
            $columns = Schema::getColumnListing($table);
            $before[$table] = [$columns, DB::table($table)->select($columns)->orderBy('id')->get()->toJson()];
        }
        (require $migrationPath)->up();
        foreach ($before as $table => [$columns, $json]) {
            $this->assertSame($json, DB::table($table)->select($columns)->orderBy('id')->get()->toJson(), $table);
        }
        foreach (['erp_quote_lines', 'erp_order_lines', 'erp_sale_return_lines', 'erp_purchase_return_lines', 'erp_commercial_number_sequences'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame(0, DB::table('erp_sales')->whereNotNull('draft_revision')->count());
        $this->assertSame(0, DB::table('erp_orders')->whereNotNull('conversion_source_id')->count());
    }
}
