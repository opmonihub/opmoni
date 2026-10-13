<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */ class AccountFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'status' => 'active', 'team_size' => 'solo'];
    }
}
