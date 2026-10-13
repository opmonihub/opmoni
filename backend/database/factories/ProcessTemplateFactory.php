<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ProcessTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessTemplate>
 */
class ProcessTemplateFactory extends Factory
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
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'cascade' => false,
            'generate_day' => fake()->numberBetween(1, 28),
            'due_day' => fake()->numberBetween(1, 28),
            'is_active' => true,
            'regimes' => null,
        ];
    }
}
