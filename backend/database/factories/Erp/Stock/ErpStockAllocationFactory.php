<?php

namespace Database\Factories\Erp\Stock;

use App\Models\Erp\Stock\ErpStockAllocation;
use App\Models\Erp\Stock\ErpStockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpStockAllocation> */
class ErpStockAllocationFactory extends Factory
{
    protected $model = ErpStockAllocation::class;

    public function definition(): array
    {
        return [
            'erp_stock_lot_id' => ErpStockLot::factory(),
            'erp_company_id' => fn (array $attributes) => ErpStockLot::findOrFail($attributes['erp_stock_lot_id'])->erp_company_id,
            'erp_establishment_id' => fn (array $attributes) => ErpStockLot::findOrFail($attributes['erp_stock_lot_id'])->erp_establishment_id,
            'erp_product_variant_id' => fn (array $attributes) => ErpStockLot::findOrFail($attributes['erp_stock_lot_id'])->erp_product_variant_id,
            'ordinal' => 0, 'kind' => 'issue', 'quantity' => '1.0000', 'cost' => '10.0000',
            'currency' => 'BRL', 'cost_status' => 'known', 'issued_at' => now(),
        ];
    }
}
