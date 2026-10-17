<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpUnit> */
class ErpUnitFactory extends Factory
{
    protected $model = ErpUnit::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'short_name' => fake()->unique()->lexify('???'),
            'actual_name' => fake()->word(),
            'allow_decimal' => true,
            'base_unit_id' => null,
            'conversion_factor' => null,
            'status' => 'active',
        ];
    }
}
