<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\SerproMonitoring;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproMonitoring>
 */
class SerproMonitoringFactory extends Factory
{
    protected $model = SerproMonitoring::class;

    /**
     * O par `account_id`/`client_id` nasce coerente quando só a conta é
     * dita: a linha é projeção de um cliente daquela conta, e uma factory
     * que os deixasse divergir fabricaria o dado que o `unique` existe
     * para impedir.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => fn (array $attributes): mixed => $attributes['client_id']
                ?? Client::factory()->company()->create(['account_id' => $attributes['account_id']])->getKey(),
            'obligation' => 'pgdasd',
            'state' => 'sem_dados',
            'source_at' => now(),
        ];
    }
}
