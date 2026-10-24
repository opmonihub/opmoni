<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpInvoiceScheme;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpCompanySetupSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_upgrade_preserves_existing_settings_and_does_not_adopt_existing_catalogs(): void
    {
        $migration = require database_path('migrations/2026_10_23_230000_create_erp_company_setup_states_table.php');
        $migration->down();
        $settings = [
            'regime' => 3, 'currency_id' => 123, 'default_unit' => 789,
            'time_zone' => null, 'fy_start_month' => 0, 'stock_overselling_enabled' => false,
            'pos_settings' => ['extension' => ['nullable' => null, 'zero' => 0, 'empty' => '', 'empty_array' => []]],
        ];
        $company = ErpCompany::factory()->create(['operating_settings' => $settings]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id, 'short_name' => 'UN', 'actual_name' => 'Custom', 'status' => 'archived']);
        $customer = ErpContact::factory()->create(['erp_company_id' => $company->id, 'name' => 'Consumidor final']);
        $beforeCompany = DB::table('erp_companies')->where('id', $company->id)->first();

        $migration->up();

        $this->assertEquals($beforeCompany, DB::table('erp_companies')->where('id', $company->id)->first());
        $this->assertSame($settings, $company->fresh()->operating_settings);
        $this->assertNull($unit->fresh()->native_key);
        $this->assertSame('Custom', $unit->fresh()->actual_name);
        $this->assertSame('archived', $unit->fresh()->status);
        $this->assertNull($customer->fresh()->native_key);
        $this->assertDatabaseCount('erp_company_setup_states', 0);
    }

    /** @return iterable<string, array{string}> */
    public static function nativePointers(): iterable
    {
        yield 'unit' => ['default_unit_id'];
        yield 'customer' => ['default_customer_id'];
        yield 'scheme' => ['default_invoice_scheme_id'];
        yield 'layout' => ['default_invoice_layout_id'];
    }

    #[DataProvider('nativePointers')]
    public function test_database_rejects_foreign_company_default_references(string $column): void
    {
        $company = ErpCompany::factory()->create();
        $foreign = ErpCompany::factory()->create();
        $references = $this->references($foreign);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');
        DB::table('erp_company_setup_states')->insert([
            'erp_company_id' => $company->id, 'preset_version' => 'br-basic-v1', 'revision' => 1,
            $column => $references[$column],
        ]);
    }

    public function test_same_company_defaults_are_valid_and_company_has_only_one_setup_state(): void
    {
        $company = ErpCompany::factory()->create();
        $state = ErpCompanySetupState::query()->create([
            'erp_company_id' => $company->id, 'preset_version' => 'br-basic-v1', 'revision' => 1,
            ...$this->references($company),
        ]);
        $this->assertSame($company->id, $state->defaultUnit->erp_company_id);
        $this->assertSame($company->id, $state->defaultCustomer->erp_company_id);
        $this->assertSame($company->id, $state->defaultInvoiceScheme->erp_company_id);
        $this->assertSame($company->id, $state->defaultInvoiceLayout->erp_company_id);

        $this->expectException(QueryException::class);
        ErpCompanySetupState::query()->create(['erp_company_id' => $company->id, 'preset_version' => 'br-basic-v1', 'revision' => 1]);
    }

    /** @return array<string, int> */
    private function references(ErpCompany $company): array
    {
        return [
            'default_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $company->id])->id,
            'default_customer_id' => ErpContact::factory()->create(['erp_company_id' => $company->id])->id,
            'default_invoice_scheme_id' => ErpInvoiceScheme::query()->create(['erp_company_id' => $company->id, 'name' => 'Scheme'])->id,
            'default_invoice_layout_id' => ErpInvoiceLayout::query()->create(['erp_company_id' => $company->id, 'name' => 'Layout'])->id,
        ];
    }
}
