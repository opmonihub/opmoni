<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpVehicle> */
class ErpVehicleFactory extends Factory
{
    protected $model = ErpVehicle::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'erp_carrier_id' => null,
            'vehicle_type' => 'truck',
            'plate' => fake()->bothify('???-#?##'),
            'uf' => 'SP',
            'color' => fake()->safeColorName(),
            'model' => fake()->word(),
            'brand' => fake()->word(),
            'wheel_type' => null,
            'body_type' => null,
            'owner_type' => null,
            'status' => 'active',
        ];
    }
}
