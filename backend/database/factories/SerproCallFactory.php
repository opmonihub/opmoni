<?php

namespace Database\Factories;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\SerproCall;
use App\Models\SerproSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproCall>
 */
class SerproCallFactory extends Factory
{
    protected $model = SerproCall::class;

    /**
     * O padrão é uma chamada respondida, porque é o que a tabela mais guarda;
     * `run_id` e `client_id` ficam nulos até serem ditos — a chamada da conta
     * não tem cliente, e a chamada fora de execução não tem run.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'run_id' => fn (array $attributes): mixed => $attributes['run_id']
                ?? SerproSyncRun::factory()->create(['account_id' => $attributes['account_id']])->getKey(),
            'client_id' => null,
            'id_sistema' => 'SITFIS',
            'id_servico' => 'RELATORIOSITFIS92',
            'version' => '2.0',
            'path' => 'Emitir',
            'billable' => true,
            'status' => SerproFailure::Success,
            'provider_code' => null,
            'response_id' => fake()->uuid(),
            'request_tag' => str_pad((string) fake()->randomNumber(8, true), 32, '0', STR_PAD_LEFT),
            'messages' => null,
            'duration_ms' => fake()->numberBetween(40, 900),
        ];
    }
}
