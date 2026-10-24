<?php

namespace App\Erp;

final class ErpCompanySetupPreset
{
    /**
     * Consumer names identify the intended native contract; availability is established by each consumer's integration.
     *
     * @return array{version: string, operating_settings: array<string, mixed>, currency: string, records: array<string, array{key: string, attributes: array<string, mixed>}>, consumers: array<string, string>}
     */
    public function manifest(): array
    {
        return [
            'version' => 'br-basic-v1',
            'operating_settings' => [
                'time_zone' => 'America/Sao_Paulo',
                'date_format' => 'd/m/Y',
                'time_format' => 24,
                'fy_start_month' => 1,
                'accounting_method' => 'fifo',
                'stock_overselling_enabled' => false,
            ],
            'currency' => 'BRL',
            'records' => [
                'unit' => [
                    'key' => 'br-basic-v1.unit.un',
                    'attributes' => [
                        'short_name' => 'UN', 'actual_name' => 'Unidade', 'allow_decimal' => false,
                        'base_unit_id' => null, 'conversion_factor' => null, 'status' => 'active',
                    ],
                ],
                'customer' => [
                    'key' => 'br-basic-v1.customer.counter-sale',
                    'attributes' => [
                        'name' => 'Consumidor final', 'is_customer' => true, 'is_supplier' => false, 'status' => 'active',
                    ],
                ],
            ],
            'consumers' => [
                'operating_settings' => 'ErpCompanyOperatingSettings',
                'unit' => 'product.unit',
                'customer' => 'counter-sale.customer',
                'currency' => 'commercial.currency',
            ],
        ];
    }

    /**
     * Only absent manifest-owned keys are missing. Explicit null, false, zero, empty strings and arrays remain values.
     * Nested settings are not owned by this preset and are never traversed or replaced.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function missingOperatingSettings(array $stored): array
    {
        return array_diff_key($this->manifest()['operating_settings'], $stored);
    }
}
