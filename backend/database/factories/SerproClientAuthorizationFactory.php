<?php

namespace Database\Factories;

use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproClientAuthorization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproClientAuthorization>
 */
class SerproClientAuthorizationFactory extends Factory
{
    protected $model = SerproClientAuthorization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'family' => '00006',
            'code' => '00006',
            'state' => SerproPowerOfAttorneyState::Established,
            'expires_on' => today()->addYear(),
            'verified_at' => now(),
        ];
    }

    public function configure(): static
    {
        // A autorização pertence à conta do cliente: uma factory que deixasse
        // pertence à conta do cliente, e uma factory que deixasse os dois
        // divergirem fabricaria exatamente o dado que a unicidade existe
        // para impedir.
        return $this->afterMaking(function (SerproClientAuthorization $authorization): void {
            if ($authorization->client instanceof Client) {
                $authorization->account_id = $authorization->client->account_id;
            }
        })->afterCreating(function (SerproClientAuthorization $authorization): void {
            if ($authorization->client instanceof Client && $authorization->account_id !== $authorization->client->account_id) {
                $authorization->account_id = $authorization->client->account_id;
                $authorization->saveQuietly();
            }
        });
    }
}
