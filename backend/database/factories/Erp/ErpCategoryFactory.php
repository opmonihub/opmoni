<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCategory;
use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpCategory> */
class ErpCategoryFactory extends Factory
{
    protected $model = ErpCategory::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'parent_id' => null,
            'name' => fake()->words(2, true),
            'status' => 'active',
        ];
    }
}
