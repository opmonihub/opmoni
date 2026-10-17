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

    public function test_summary_omits_unsafe_resource_values_and_invalid_record_ids_without_changing_history(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $account = Account::factory()->create();
        $metadataRows = [
            ['resource' => 'Bearer historical-secret-value', 'resource_id' => 0],
            ['resource' => 'unknown_resource', 'resource_id' => null],
            ['resource' => str_repeat('clients', 100), 'resource_id' => -1],
            ['resource' => 'clients', 'resource_id' => '42'],
            ['resource' => 'clients', 'resource_id' => 42, 'count' => 0, 'certificate_id' => null],
        ];
        $logs = [];
        foreach ($metadataRows as $metadata) {
            $log = (new SupportAccessLog)->forceFill(['super_admin_user_id' => $admin->id, 'account_id' => $account->id, 'action' => 'update', 'created_at' => '2026-10-17 12:00:00', 'metadata' => $metadata]);
            $log->save();
            $logs[] = $log;
        }
        $rows = $this->actingAs($admin)->getJson('/api/v1/admin/summary')->assertOk()->json('data.recent_support_logs');
        $this->assertSame([
            ['resource' => 'clients', 'resource_id' => 42, 'count' => 0, 'certificate_id' => null],
            ['resource' => 'clients'],
            [],
            [],
            [],
        ], array_column($rows, 'metadata'));
        foreach ($logs as $index => $log) {
            $this->assertSame($metadataRows[$index], $log->fresh()->metadata);
        }
    }
}
