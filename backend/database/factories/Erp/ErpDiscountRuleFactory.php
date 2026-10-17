<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpDiscountRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpDiscountRule> */
class ErpDiscountRuleFactory extends Factory
{
    protected $model = ErpDiscountRule::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->words(2, true),
            'kind' => 'percentage',
            'value' => '5.0000',
            'coupon_code' => null,
            'conditions' => null,
            'status' => 'active',
        ];
    }
}
