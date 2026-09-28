<?php

namespace Database\Factories;

use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalGap>
 */
class FiscalGapFactory extends Factory
{
    /**
     * Define the model's default state: posição pendente de primeira vez, que
     * ainda não custou consulta nenhuma e por isso está devida agora.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'source' => FiscalSource::NfeDistribuicao,
            'nsu' => fake()->numberBetween(1, 999999),
            'attempts' => 0,
            'next_attempt_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (FiscalGap $gap): void {
            if ($gap->client instanceof Client) {
                $gap->account_id = $gap->client->account_id;
            }
        })->afterCreating(function (FiscalGap $gap): void {
            if ($gap->client instanceof Client && $gap->account_id !== $gap->client->account_id) {
                $gap->account_id = $gap->client->account_id;
                $gap->saveQuietly();
            }
        });
    }
}
