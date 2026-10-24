<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpProductTaxProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpProductTaxProfile> */
class ErpProductTaxProfileFactory extends Factory
{
    protected $model = ErpProductTaxProfile::class;

    public function definition(): array
    {
        return ['erp_company_id' => ErpCompany::factory(), 'name' => fake()->words(3, true), 'status' => 'active', 'current_revision' => null];
    }
}
