<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpSalesPlan> */
class OfficeErpSalesPlanFactory extends Factory
{
    protected $model = OfficeErpSalesPlan::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'status' => 'active',
            'archived_at' => null,
            'current_revision_id' => null,
        ];
    }
}
