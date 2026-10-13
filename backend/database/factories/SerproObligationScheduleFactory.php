<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SerproObligationSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproObligationSchedule>
 */
class SerproObligationScheduleFactory extends Factory
{
    protected $model = SerproObligationSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'obligation' => 'declaracoes/pgdas',
            'day' => 15,
        ];
    }
}
