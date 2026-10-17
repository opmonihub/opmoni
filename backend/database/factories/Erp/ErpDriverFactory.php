<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpDriver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpDriver> */
class ErpDriverFactory extends Factory
{
    protected $model = ErpDriver::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->name(),
            'tax_id' => null,
            'license_number' => fake()->optional()->bothify('########'),
            'status' => 'active',
        ];
    }
}
