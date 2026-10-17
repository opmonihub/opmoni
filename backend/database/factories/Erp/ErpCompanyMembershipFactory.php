<?php

namespace Database\Factories\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpCompanyMembership> */
class ErpCompanyMembershipFactory extends Factory
{
    protected $model = ErpCompanyMembership::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'user_id' => User::factory(),
            'role' => ErpCompanyRole::Owner->value,
            'action_permissions' => null,
        ];
    }

    public function role(ErpCompanyRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role->value]);
    }
}
