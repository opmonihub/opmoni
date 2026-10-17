<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpBrand> */
class ErpBrandFactory extends Factory
{
    protected $model = ErpBrand::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->company(),
            'status' => 'active',
        ];
    }
}
