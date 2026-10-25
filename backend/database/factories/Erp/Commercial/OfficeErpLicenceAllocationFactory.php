<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpLicenceAllocation> */
class OfficeErpLicenceAllocationFactory extends Factory
{
    protected $model = OfficeErpLicenceAllocation::class;

    public function definition(): array
    {
        return [
            'contract_id' => OfficeErpContract::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpContract::query()->findOrFail($attributes['contract_id'])->account_id,
            'erp_company_id' => null,
            'state' => 'reserved',
            'expires_at' => fn (array $attributes): ?\Illuminate\Support\Carbon => $attributes['state'] === 'occupied' ? null : now()->addDays(7),
            'released_at' => null,
        ];
    }
}
