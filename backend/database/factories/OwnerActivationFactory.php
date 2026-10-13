<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\OwnerActivation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OwnerActivation> */ class OwnerActivationFactory extends Factory
{
    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'email' => fake()->unique()->safeEmail(), 'owner_name' => fake()->name(), 'token_hash' => hash('sha256', bin2hex(random_bytes(32))), 'expires_at' => now()->addDays(7)];
    }
}
