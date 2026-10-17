<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpContact> */
class ErpContactFactory extends Factory
{
    protected $model = ErpContact::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->name(),
            'trade_name' => fake()->optional()->company(),
            'tax_id' => null,
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'is_customer' => true,
            'is_supplier' => false,
            'address' => null,
            'notes' => null,
            'status' => 'active',
        ];
    }
}
