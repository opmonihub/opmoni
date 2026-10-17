<?php

namespace Database\Factories\Erp;

use App\Models\Account;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpOfficeDelegation> */
class ErpOfficeDelegationFactory extends Factory
{
    protected $model = ErpOfficeDelegation::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'account_id' => Account::factory(),
            'authorized_by_user_id' => User::factory(),
            'permission_allowlist' => ['erp.read'],
            'status' => 'active',
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => 'revoked', 'revoked_at' => now()]);
    }
}
