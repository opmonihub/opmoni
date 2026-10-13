<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountUser>
 */
class AccountUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'user_id' => User::factory(),
            'role' => 'user',
            'inviter_id' => null,
        ];
    }
}
