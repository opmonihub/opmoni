<?php

namespace Database\Factories;

use App\Enums\SerproSyncRunState;
use App\Models\Account;
use App\Models\SerproSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproSyncRun>
 */
class SerproSyncRunFactory extends Factory
{
    protected $model = SerproSyncRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'state' => SerproSyncRunState::Queued,
        ];
    }

    /**
     * Em curso: o carimbo de início é o que separa `running` de uma fila que
     * ninguém pegou.
     */
    public function running(): static
    {
        return $this->state([
            'state' => SerproSyncRunState::Running,
            'started_at' => now(),
        ]);
    }
}
