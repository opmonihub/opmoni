<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpCommercialOperation> */
class OfficeErpCommercialOperationFactory extends Factory
{
    protected $model = OfficeErpCommercialOperation::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'actor_kind' => 'office_admin',
            'actor_identity' => (string) fake()->uuid(),
            'idempotency_key' => (string) fake()->uuid(),
            'action' => 'plan.create',
            'payload_hash' => hash('sha256', '{}'),
            'payload' => [],
            'outcome' => [],
        ];
    }
}
