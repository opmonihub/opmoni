<?php

namespace Database\Factories\Erp\Stock;

use App\Models\Erp\Stock\ErpStockAllocation;
use App\Models\Erp\Stock\ErpStockCostSettlement;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ErpStockCostSettlement> */
class ErpStockCostSettlementFactory extends Factory
{
    protected $model = ErpStockCostSettlement::class;

    public function definition(): array
    {
        return [
            'erp_stock_allocation_id' => ErpStockAllocation::factory(),
            'erp_stock_lot_id' => fn (array $attributes) => ErpStockAllocation::findOrFail($attributes['erp_stock_allocation_id'])->erp_stock_lot_id,
            'erp_company_id' => fn (array $attributes) => ErpStockAllocation::findOrFail($attributes['erp_stock_allocation_id'])->erp_company_id,
            'erp_establishment_id' => fn (array $attributes) => ErpStockAllocation::findOrFail($attributes['erp_stock_allocation_id'])->erp_establishment_id,
            'erp_product_variant_id' => fn (array $attributes) => ErpStockAllocation::findOrFail($attributes['erp_stock_allocation_id'])->erp_product_variant_id,
            'erp_stock_movement_id' => function (array $attributes): int {
                $lot = ErpStockLot::findOrFail($attributes['erp_stock_lot_id']);

                return ErpStockMovement::query()->create(['erp_company_id' => $lot->erp_company_id, 'erp_establishment_id' => $lot->erp_establishment_id, 'erp_product_variant_id' => $lot->erp_product_variant_id, 'direction' => 'in', 'quantity' => '1.0000', 'source_type' => 'fixture', 'source_id' => $lot->id, 'source_operation_key' => (string) Str::uuid(), 'posted_at' => now()])->id;
            },
            'quantity' => '1.0000', 'cost' => '10.0000', 'currency' => 'BRL', 'posted_at' => now(),
        ];
    }
}
