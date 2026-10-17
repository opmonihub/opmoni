<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpEstablishment> */
class ErpEstablishmentFactory extends Factory
{
    protected $model = ErpEstablishment::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->city().' · Matriz',
            'cnpj' => null,
            'status' => 'active',
        ];
    }
}
