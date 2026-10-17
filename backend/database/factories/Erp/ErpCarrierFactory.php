<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCarrier;
use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpCarrier> */
class ErpCarrierFactory extends Factory
{
    protected $model = ErpCarrier::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'legal_name' => fake()->company(),
            'tax_id' => null,
            'ie' => null,
            'address' => null,
            'status' => 'active',
        ];
    }
}
