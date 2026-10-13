<?php

namespace Database\Factories;

use App\Enums\SerproManualSearchState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproManualSearch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproManualSearch>
 */
class SerproManualSearchFactory extends Factory
{
    protected $model = SerproManualSearch::class;

    /**
     * O par `account_id`/`client_id` nasce coerente quando só a conta é
     * dita: a busca é de um cliente daquela conta, e uma factory que os
     * deixasse divergir fabricaria o dado que a cota e o painel leem.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => fn (array $attributes): mixed => $attributes['client_id']
                ?? Client::factory()->company()->create(['account_id' => $attributes['account_id']])->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'state' => SerproManualSearchState::Queued,
            'mode' => 'full',
            'recalculate_date' => null,
        ];
    }

    /**
     * Terminada com resposta do provedor: a busca que saiu, voltou e vale.
     */
    public function completed(): static
    {
        return $this->state([
            'state' => SerproManualSearchState::Completed,
        ]);
    }

    /**
     * Falhada com motivo: o operador pode ler o porquê na linha.
     */
    public function failed(string $reason = 'falha_do_trabalhador'): static
    {
        return $this->state([
            'state' => SerproManualSearchState::Failed,
            'reason' => $reason,
        ]);
    }
}
