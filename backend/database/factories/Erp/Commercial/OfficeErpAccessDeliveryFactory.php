<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpAccessDelivery> */
class OfficeErpAccessDeliveryFactory extends Factory
{
    protected $model = OfficeErpAccessDelivery::class;

    public function definition(): array
    {
        return [
            'member_invitation_id' => OfficeErpMemberInvitation::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpMemberInvitation::findOrFail($attributes['member_invitation_id'])->account_id,
            'erp_company_id' => fn (array $attributes): int => (int) OfficeErpMemberInvitation::findOrFail($attributes['member_invitation_id'])->erp_company_id,
            'registration_id' => fn (array $attributes): int => (int) OfficeErpMemberInvitation::findOrFail($attributes['member_invitation_id'])->registration_id,
            'recipient_email' => fn (array $attributes): string => OfficeErpMemberInvitation::findOrFail($attributes['member_invitation_id'])->email,
            'operation_id' => fn (array $attributes): int => OfficeErpCommercialOperation::factory()->create(['account_id' => $attributes['account_id']])->id,
            'purpose' => 'member_invitation', 'expires_at' => now()->addDays(7), 'payload' => ['token' => 'test-only-token'],
        ];
    }

    public function forInvitation(OfficeErpMemberInvitation $invitation): static
    {
        return $this->state(fn (): array => [
            'member_invitation_id' => $invitation->id, 'account_id' => $invitation->account_id,
            'erp_company_id' => $invitation->erp_company_id, 'registration_id' => $invitation->registration_id,
            'recipient_email' => $invitation->email,
        ]);
    }
}
