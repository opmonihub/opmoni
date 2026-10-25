<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpContractRevision> */
class OfficeErpContractRevisionFactory extends Factory
{
    protected $model = OfficeErpContractRevision::class;

    public function definition(): array
    {
        return [
            'contract_id' => OfficeErpContract::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpContract::query()->findOrFail($attributes['contract_id'])->account_id,
            'revision_number' => 1,
            'sales_plan_revision_id' => null,
            'price' => '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 0,
            'module_keys' => [],
            'service_period_start' => null,
            'service_period_end' => null,
            'reason' => null,
            'created_by_user_id' => null,
        ];
    }
}
