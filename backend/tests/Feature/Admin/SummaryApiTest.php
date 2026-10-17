<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\SupportAccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class SummaryApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_summary_returns_persisted_counts_and_empty_history(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->getJson('/api/v1/admin/summary')->assertOk()
            ->assertExactJson(['data' => ['totals' => ['accounts' => 0, 'plans' => 0, 'subscriptions' => 0, 'users' => 1], 'recent_support_logs' => []]]);
    }

    public function test_summary_uses_timestamp_then_id_order_and_limits_to_five_safe_rows(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $account = Account::factory()->create(['settings' => ['private' => 'hidden-settings']]);
        User::factory()->create();
        $ids = [];
        foreach (['2026-10-16 12:00:00', '2026-10-17 12:00:00', '2026-10-17 12:00:00', '2026-10-15 12:00:00', '2026-10-18 12:00:00', '2026-10-19 12:00:00'] as $time) {
            $log = (new SupportAccessLog)->forceFill(['super_admin_user_id' => $admin->id, 'account_id' => $account->id, 'action' => 'update', 'created_at' => $time, 'metadata' => ['resource' => 'clients', 'arbitrary' => 'hidden-token']]);
            $log->save();
            $ids[] = $log->id;
        }
        $response = $this->actingAs($admin)->getJson('/api/v1/admin/summary')->assertOk()
            ->assertJsonPath('data.totals', ['accounts' => 1, 'plans' => 3, 'subscriptions' => 1, 'users' => 2])
            ->assertJsonCount(5, 'data.recent_support_logs');
        $this->assertSame([$ids[5], $ids[4], $ids[2], $ids[1], $ids[0]], array_column($response->json('data.recent_support_logs'), 'id'));
        $response->assertJsonPath('data.recent_support_logs.0.metadata', ['resource' => 'clients'])
            ->assertJsonMissingPath('data.recent_support_logs.0.account.settings')
            ->assertJsonMissingPath('data.recent_support_logs.0.super_admin.is_super_admin');
    }
}
