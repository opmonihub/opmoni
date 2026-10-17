<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpCompany> */
class ErpCompanyFactory extends Factory
{
    protected $model = ErpCompany::class;

    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'name' => fake()->company(),
            'cnpj' => str_pad((string) (10000000000000 + $sequence), 14, '0', STR_PAD_LEFT),
            'status' => 'active',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }
}
