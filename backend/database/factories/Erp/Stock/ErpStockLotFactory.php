<?php

namespace Database\Factories\Erp\Stock;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpStockLot> */
class ErpStockLotFactory extends Factory
{
    protected $model = ErpStockLot::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'erp_establishment_id' => fn (array $attributes) => ErpEstablishment::factory()->create(['erp_company_id' => $attributes['erp_company_id']])->id,
            'erp_product_variant_id' => function (array $attributes): int {
                $unit = ErpUnit::factory()->create(['erp_company_id' => $attributes['erp_company_id']]);
                $product = ErpProduct::factory()->create(['erp_company_id' => $attributes['erp_company_id'], 'erp_unit_id' => $unit->id]);

                return ErpProductVariant::factory()->create(['erp_product_id' => $product->id])->id;
            },
            'quantity' => '1.0000', 'available_quantity' => '1.0000', 'total_cost' => '10.0000',
            'consumed_quantity' => '0.0000', 'consumed_cost' => '0.0000',
            'currency' => 'BRL', 'cost_status' => 'known', 'received_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ErpStockLot $lot): void {
            ErpStockBalance::query()->firstOrCreate(['erp_company_id' => $lot->erp_company_id, 'erp_establishment_id' => $lot->erp_establishment_id, 'erp_product_variant_id' => $lot->erp_product_variant_id], ['quantity_on_hand' => $lot->quantity, 'ledger_initialized_at' => now()]);
        });
    }
}
