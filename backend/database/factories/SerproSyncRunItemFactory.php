<?php

namespace Database\Factories;

use App\Enums\SerproSyncItemState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproSyncRunItem>
 */
class SerproSyncRunItemFactory extends Factory
{
    protected $model = SerproSyncRunItem::class;

    /**
     * O trio `account_id`/`run_id`/`client_id` nasce coerente quando só a
     * conta é dita: run e cliente são criados já pertencendo a ela, porque um
     * item que apontasse para uma execução ou um cliente de outra conta
     * fabricaria o dado que a unicidade e o isolamento existem para impedir.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'run_id' => fn (array $attributes): mixed => $attributes['run_id']
                ?? SerproSyncRun::factory()->create(['account_id' => $attributes['account_id']])->getKey(),
            'client_id' => fn (array $attributes): mixed => $attributes['client_id']
                ?? Client::factory()->company()->create(['account_id' => $attributes['account_id']])->getKey(),
            'state' => SerproSyncItemState::NotProcessed,
        ];
    }
}
