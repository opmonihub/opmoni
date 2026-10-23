<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpProduct> */
class ErpProductFactory extends Factory
{
    protected $model = ErpProduct::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'sku' => fake()->unique()->bothify('SKU-####'),
            'name' => fake()->words(3, true),
            'kind' => 'goods',
            'track_stock' => true,
            'erp_unit_id' => ErpUnit::factory(),
            'erp_brand_id' => null,
            'erp_category_id' => null,
            'sale_price' => '10.0000',
            'warranty_months' => null,
            'warranty_description' => null,
            'barcode' => null,
            'barcode_type' => 'C128',
            'status' => 'active',
        ];
    }
}
