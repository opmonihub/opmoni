<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpPlan> */
class ErpPlanFactory extends Factory
{
    protected $model = ErpPlan::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'quotas' => null,
        ];
    }
}
