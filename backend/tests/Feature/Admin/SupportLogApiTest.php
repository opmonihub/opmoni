<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\SupportAccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class SupportLogApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_support_logs_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/support/logs')->assertUnauthorized();
    }

    public function test_support_logs_require_super_admin(): void
    {
        $account = Account::factory()->create();
        $member = User::factory()->create();
        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $member->getKey(),
            'role' => 'admin',
        ]);
        $this->selectBusinessAccount($member, $account->getKey());

        $this->actingAs($member, 'web')
            ->getJson('/api/v1/admin/support/logs')
            ->assertForbidden();
    }

    public function test_super_admin_without_office_membership_can_browse_audit_logs(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/support/logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_support_logs_are_ordered_by_descending_id_and_paginated(): void
    {
        $superAdmin = $this->superAdmin();
        $account = Account::factory()->create();

        foreach (range(1, 16) as $index) {
            $this->createLog($superAdmin, $account, "action-{$index}");
        }

        $response = $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/support/logs')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame(range(16, 2), $ids);
        $this->assertSame($ids, collect($ids)->sort()->reverse()->values()->all());
        $this->assertSame(16, $response->json('meta.total'));
        $this->assertSame(15, count($response->json('data')));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_support_logs_filter_by_existing_account_id(): void
    {
        $superAdmin = $this->superAdmin();
        $target = Account::factory()->create();
        $other = Account::factory()->create();

        $this->createLog($superAdmin, $target, 'target-action');
        $this->createLog($superAdmin, $other, 'other-action');

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/support/logs?account_id='.$target->getKey())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'target-action')
            ->assertJsonPath('data.0.account.id', $target->getKey());
    }

    public function test_support_logs_reject_unknown_account_filter(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/support/logs?account_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_id');
    }

    public function test_support_log_metadata_omits_credential_and_session_material(): void
    {
        $superAdmin = $this->superAdmin();
        $account = Account::factory()->create();

        $this->createLog($superAdmin, $account, 'certificate', [
            'resource' => 'clients',
            'resource_id' => 42,
            'password' => 'secret-value',
            'nested' => ['session_id' => 'sess-123', 'note' => 'safe'],
            'access_token' => 'token-value',
        ]);

        $payload = $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/support/logs')
            ->assertOk()
            ->json('data.0');

        $encoded = json_encode($payload['metadata'], JSON_THROW_ON_ERROR);

        $this->assertSame([
            'resource' => 'clients',
            'resource_id' => 42,
            'nested' => ['note' => 'safe'],
        ], $payload['metadata']);
        $this->assertStringNotContainsString('secret-value', $encoded);
        $this->assertStringNotContainsString('token-value', $encoded);
        $this->assertStringNotContainsString('sess-123', $encoded);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('session_id', $payload);
    }

    private function superAdmin(): User
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $home = Account::factory()->create();
        AccountUser::create([
            'account_id' => $home->getKey(),
            'user_id' => $superAdmin->getKey(),
            'role' => 'admin',
        ]);
        $this->selectBusinessAccount($superAdmin, $home->getKey());

        return $superAdmin->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createLog(User $superAdmin, Account $account, string $action, array $metadata = []): SupportAccessLog
    {
        $log = (new SupportAccessLog)->forceFill([
            'super_admin_user_id' => $superAdmin->getKey(),
            'account_id' => $account->getKey(),
            'action' => $action,
            'metadata' => $metadata === [] ? ['resource' => 'test'] : $metadata,
            'ip' => '127.0.0.1',
        ]);
        $log->save();

        return $log;
    }
}
