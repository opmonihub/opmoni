<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class SubscriptionValidationTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_nonexistent_plan_update_returns_422_without_changing_subscription(): void
    {
        $this->seed(PlanSeeder::class);
        $account = Account::factory()->create();
        $subscription = $account->subscription()->firstOrFail();
        $before = $subscription->fresh()->getAttributes();
        $nonexistentPlanId = (int) Plan::query()->max('id') + 1;

        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->patchJson('/api/v1/admin/subscriptions/'.$subscription->id, ['plan_id' => $nonexistentPlanId])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');

        $this->assertSame($before, $subscription->fresh()->getAttributes());
    }
}
