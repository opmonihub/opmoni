<?php

namespace Tests\Unit;

use App\Erp\ErpCompanySetupPreset;
use PHPUnit\Framework\TestCase;

class ErpCompanySetupPresetTest extends TestCase
{
    public function test_preset_allows_only_operational_preferences_and_non_identifying_catalog_facts(): void
    {
        $manifest = (new ErpCompanySetupPreset)->manifest();

        $this->assertSame('br-basic-v1', $manifest['version']);
        $this->assertSame([
            'time_zone', 'date_format', 'time_format', 'fy_start_month', 'accounting_method', 'stock_overselling_enabled',
        ], array_keys($manifest['operating_settings']));
        $this->assertSame('BRL', $manifest['currency']);
        $this->assertSame(['unit', 'customer'], array_keys($manifest['records']));
        $this->assertSame([
            'short_name' => 'UN', 'actual_name' => 'Unidade', 'allow_decimal' => false,
            'base_unit_id' => null, 'conversion_factor' => null, 'status' => 'active',
        ], $manifest['records']['unit']['attributes']);
        $this->assertSame([
            'name' => 'Consumidor final', 'is_customer' => true, 'is_supplier' => false, 'status' => 'active',
        ], $manifest['records']['customer']['attributes']);
        $this->assertSame('ErpCompanyOperatingSettings', $manifest['consumers']['operating_settings']);
        $this->assertSame('product.unit', $manifest['consumers']['unit']);
        $this->assertSame('counter-sale.customer', $manifest['consumers']['customer']);
        $this->assertSame('commercial.currency', $manifest['consumers']['currency']);
    }

    public function test_only_absent_owned_settings_are_missing_including_explicit_null_false_zero_and_empty(): void
    {
        $preset = new ErpCompanySetupPreset;
        $settings = [
            'time_zone' => null, 'date_format' => '', 'time_format' => 0,
            'fy_start_month' => [], 'stock_overselling_enabled' => false,
            'extension' => ['keep' => null],
        ];

        $this->assertSame(['accounting_method' => 'fifo'], $preset->missingOperatingSettings($settings));
        $this->assertSame([], $preset->missingOperatingSettings([...$settings, 'accounting_method' => null]));
    }
}
