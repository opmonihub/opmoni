<?php

namespace Database\Factories;

use App\Enums\ClientPersonType;
use App\Enums\ClientStatus;
use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
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
            'name' => fake()->company(),
        ];
    }

    public function company(): static
    {
        return $this->state(fn (): array => [
            'person_type' => ClientPersonType::Company,
            'tax_id' => fake()->unique()->numerify('##############'),
            'name' => fake()->company(),
            'status' => ClientStatus::Active,
            'tax_regime' => TaxRegime::PresumedProfit,
        ]);
    }

    public function individual(): static
    {
        return $this->state(fn (): array => [
            'person_type' => ClientPersonType::Individual,
            'tax_id' => fake()->unique()->numerify('###########'),
            'name' => fake()->name(),
            'status' => ClientStatus::Active,
            'tax_regime' => TaxRegime::NotApplicable,
        ]);
    }
}
