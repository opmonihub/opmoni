<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountMembership> */ class AccountMembershipFactory extends Factory
{
    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'user_id' => User::factory(), 'role' => 'admin'];
    }
}
