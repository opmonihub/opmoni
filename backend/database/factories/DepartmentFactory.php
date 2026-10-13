<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->randomElement(['neutral', 'primary', 'success', 'info', 'warning', 'error']),
        ];
    }
}
