<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpAuditEvent> */
class OfficeErpAuditEventFactory extends Factory
{
    protected $model = OfficeErpAuditEvent::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'operation_id' => null,
            'actor_kind' => 'office_admin',
            'actor_identity' => (string) fake()->uuid(),
            'actor_user_id' => null,
            'action' => 'plan.create',
            'subject_type' => null,
            'subject_id' => null,
            'metadata' => [],
        ];
    }
}
