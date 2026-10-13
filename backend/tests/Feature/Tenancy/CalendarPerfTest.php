<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class CalendarPerfTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_calendar_with_range_ok_and_tags_paginated(): void
    {
        $member = $this->memberOf(Account::factory()->create(), 'operador');

        $this->actingAs($member, 'web')
            ->getJson('/api/v1/work/calendar?from=2026-01-01&to=2026-01-31')->assertOk();
        $this->actingAs($member, 'web')->getJson('/api/v1/tags')
            ->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
