<?php

namespace Database\Factories;

use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalCursor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalCursor>
 */
class FiscalCursorFactory extends Factory
{
    /**
     * Define the model's default state: cursor de primeira execução, que
     * ainda não consumiu nada e nunca falhou.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'source' => FiscalSource::NfeDistribuicao,
            'last_nsu' => 0,
            'last_run_at' => null,
            'last_success_at' => null,
            'last_error' => null,
            'blocked_until' => null,
            'last_seen_at' => null,
        ];
    }

    /**
     * Cursor parado por consumo indevido. A parada precisa sobreviver entre
     * execuções, então vive na coluna `blocked_until` e não em memória.
     */
    public function blocked(int $minutes = 60): static
    {
        return $this->state(fn (): array => [
            'last_run_at' => now(),
            'last_error' => 'Consumo indevido: a consulta será retomada depois.',
            'blocked_until' => now()->addMinutes($minutes),
        ]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (FiscalCursor $cursor): void {
            if ($cursor->client instanceof Client) {
                $cursor->account_id = $cursor->client->account_id;
            }
        })->afterCreating(function (FiscalCursor $cursor): void {
            if ($cursor->client instanceof Client && $cursor->account_id !== $cursor->client->account_id) {
                $cursor->account_id = $cursor->client->account_id;
                $cursor->saveQuietly();
            }
        });
    }
}
