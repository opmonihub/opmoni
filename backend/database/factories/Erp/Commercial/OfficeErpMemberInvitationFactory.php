<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OfficeErpMemberInvitation> */
class OfficeErpMemberInvitationFactory extends Factory
{
    protected $model = OfficeErpMemberInvitation::class;

    public function definition(): array
    {
        return [
            'registration_id' => OfficeErpCompanyRegistration::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpCompanyRegistration::findOrFail($attributes['registration_id'])->account_id,
            'erp_company_id' => fn (array $attributes): int => (int) OfficeErpCompanyRegistration::findOrFail($attributes['registration_id'])->erp_company_id,
            'name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'role' => 'viewer',
            'permissions' => [], 'establishment_ids' => [],
            'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDays(7),
        ];
    }
}
