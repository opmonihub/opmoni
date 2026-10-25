<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpOwnerInvitation> */
class OfficeErpOwnerInvitationFactory extends Factory
{
    protected $model = OfficeErpOwnerInvitation::class;

    public function definition(): array
    {
        return [
            'contract_id' => OfficeErpContract::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpContract::query()->findOrFail($attributes['contract_id'])->account_id,
            'owner_name' => fake()->name(),
            'owner_email' => fake()->unique()->safeEmail(),
            'seller_snapshot' => fn (array $attributes): array => [
                'account_id' => (int) $attributes['account_id'],
                'name' => (string) Account::query()->findOrFail($attributes['account_id'])->name,
            ],
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'consumed_at' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
        ];
    }
}
