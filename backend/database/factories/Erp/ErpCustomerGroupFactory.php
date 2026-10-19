<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCustomerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpCustomerGroup> */
class ErpCustomerGroupFactory extends Factory
{
    protected $model = ErpCustomerGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->words(2, true),
            'percentage_adjustment' => '0.0000',
            'status' => 'active',
        ];
    }
}
