<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPriceGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpPriceGroup> */
class ErpPriceGroupFactory extends Factory
{
    protected $model = ErpPriceGroup::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->words(2, true),
            'is_default' => false,
            'percentage_adjustment' => '0',
            'status' => 'active',
        ];
    }
}
