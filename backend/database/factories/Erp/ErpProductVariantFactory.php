<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpProductVariant> */
class ErpProductVariantFactory extends Factory
{
    protected $model = ErpProductVariant::class;

    public function definition(): array
    {
        return [
            'erp_product_id' => ErpProduct::factory(),
            'sku' => fake()->optional()->bothify('VAR-####'),
            'name' => fake()->word(),
            'sale_price' => '12.5000',
            'barcode' => null,
            'barcode_type' => null,
            'status' => 'active',
        ];
    }
}
