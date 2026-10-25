<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpSalesPlanRevision> */
class OfficeErpSalesPlanRevisionFactory extends Factory
{
    protected $model = OfficeErpSalesPlanRevision::class;

    public function definition(): array
    {
        return [
            'sales_plan_id' => OfficeErpSalesPlan::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpSalesPlan::query()->findOrFail($attributes['sales_plan_id'])->account_id,
            'revision_number' => 1,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price' => '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 0,
            'module_keys' => [],
            'published_at' => now(),
        ];
    }
}
