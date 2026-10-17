<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => fn () => Account::withoutEvents(fn () => Account::factory()->create())->id,
            'plan_id' => Plan::factory(),
            'status' => 'active',
        ];
    }
}
