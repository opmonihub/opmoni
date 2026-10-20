<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErpFinanceUpgradeTest extends TestCase
{
    public function test_additive_upgrade_retains_original_values_ids_unknown_direction_and_closed_counts(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Finance upgrade proof requires isolated SQLite memory.');
        }
        config(['database.connections.finance_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'database.default' => 'finance_upgrade']);
        $migrationPath = database_path('migrations/2026_10_20_141059_add_erp_cash_movements_and_reversals.php');
        $prior = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => $path !== $migrationPath));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'finance_upgrade', '--path' => $prior, '--realpath' => true, '--force' => true, '--no-interaction' => true]));
        $company = ErpCompany::factory()->create();
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $user = User::factory()->create();
        $register = DB::table('erp_cash_registers')->insertGetId(['erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id, 'name' => 'Original']);
        $session = DB::table('erp_cash_sessions')->insertGetId(['erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id, 'erp_cash_register_id' => $register, 'operator_user_id' => $user->id, 'opening_amount' => '100.1234', 'expected_closing_amount' => '99.9999', 'declared_closing_amount' => '99.0000', 'closing_difference' => '-0.9999', 'status' => 'closed', 'opened_by_user_id' => $user->id, 'opened_at' => now(), 'closed_at' => now(), 'close_operation_key' => 'historical-close']);
        $payment = DB::table('erp_payments')->insertGetId(['erp_company_id' => $company->id, 'amount' => '10.1234', 'method' => 'cash', 'source_operation_key' => 'old-key', 'reversal_of_id' => 999999, 'metadata' => '{"original":"exact"}']);
        $obligation = DB::table('erp_obligations')->insertGetId(['erp_company_id' => $company->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '10.1234', 'open_amount' => '0', 'status' => 'settled', 'source_operation_key' => 'old-key']);
        DB::table('erp_payment_allocations')->insert(['erp_payment_id' => $payment, 'erp_obligation_id' => $obligation, 'amount' => '10.1234', 'source_operation_key' => 'old-key']);
        $before = [];
        foreach (['users', 'erp_companies', 'erp_cash_registers', 'erp_cash_sessions', 'erp_obligations', 'erp_payments', 'erp_payment_allocations'] as $table) {
            $columns = Schema::getColumnListing($table);
            $before[$table] = [$columns, DB::table($table)->select($columns)->orderBy('id')->get()->toJson()];
        }
        $migration = require $migrationPath;
        $migration->up();
        foreach ($before as $table => [$columns, $json]) {
            $this->assertSame($json, DB::table($table)->select($columns)->orderBy('id')->get()->toJson(), $table);
        }
        $this->assertNull(DB::table('erp_payments')->where('id', $payment)->value('direction'));
        $this->assertNull(DB::table('erp_cash_sessions')->where('id', $session)->value('currency'));
        $this->assertSame(0, DB::table('erp_cash_movements')->count());
        try {
            $migration->down();
            $this->fail('Financial history rollback must be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('retained', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('erp_payments')->count());
    }
}
