<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpContract> */
class OfficeErpContractFactory extends Factory
{
    protected $model = OfficeErpContract::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory()->company(),
            'account_id' => fn (array $attributes): int => (int) Client::query()->findOrFail($attributes['client_id'])->account_id,
            'sales_plan_id' => null,
            'erp_company_id' => null,
            'agreement_status' => 'draft',
            'current_revision_id' => null,
            'pending_revision_id' => null,
            'service_anchor_on' => null,
            'billing_timezone' => 'America/Sao_Paulo',
            'next_period_index' => null,
            'next_period_month_offset' => null,
            'cancel_effective_on' => null,
            'manual_hold' => false,
            'accepted_at' => null,
            'effective_at' => null,
            'canceled_at' => null,
            'cancellation_reason' => null,
        ];
    }
}
