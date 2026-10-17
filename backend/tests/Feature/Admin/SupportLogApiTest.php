<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\SupportAccessLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        ], $payload['metadata']);
        $this->assertStringNotContainsString('secret-value', $encoded);
        $this->assertStringNotContainsString('token-value', $encoded);
        $this->assertStringNotContainsString('sess-123', $encoded);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('session_id', $payload);
    }

    public function test_audit_uses_minimal_related_projections_and_does_not_mutate_stored_metadata(): void
    {
        $admin = $this->superAdmin();
        $account = Account::factory()->create(['settings' => ['private' => 'settings-secret', 'pfx' => ['blob' => 'certificate-secret']]]);
        $metadata = ['resource' => 'clients', 'resource_id' => 42, 'arbitrary' => 'bearer-secret', 'pfx' => ['body' => 'certificate-secret'], 'nested' => ['note' => 'token-under-note'], 'resource_object' => ['credential' => 'secret']];
        $log = $this->createLog($admin, $account, 'update', $metadata);
        $response = $this->actingAs($admin)->getJson('/api/v1/admin/support/logs')->assertOk();
        $response->assertJsonPath('data.0.metadata', ['resource' => 'clients', 'resource_id' => 42])
            ->assertJsonPath('data.0.account', ['id' => $account->id, 'name' => $account->name, 'status' => $account->status])
            ->assertJsonPath('data.0.super_admin', ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email]);
        $this->assertSame($metadata, $log->fresh()->metadata);
    }

    public function test_audit_history_prevents_account_deletion_instead_of_cascading_away(): void
    {
        $admin = $this->superAdmin();
        $account = Account::factory()->create();
        $log = $this->createLog($admin, $account, 'update');
        try {
            DB::transaction(fn () => $account->delete());
            $this->fail('Account with audit history must be retained.');
        } catch (QueryException) {
            $this->assertDatabaseHas('support_access_logs', ['id' => $log->id, 'account_id' => $account->id, 'super_admin_user_id' => $admin->id]);
            $this->assertDatabaseHas('accounts', ['id' => $account->id]);
        }
    }

    public function test_audit_actor_deletion_is_restricted_and_history_remains(): void
    {
        $admin = $this->superAdmin();
        $account = Account::factory()->create();
        $log = $this->createLog($admin, $account, 'update');
        try {
            DB::transaction(fn () => $admin->delete());
            $this->fail('Audit actor must remain referenced.');
        } catch (QueryException) {
            $this->assertDatabaseHas('support_access_logs', ['id' => $log->id, 'super_admin_user_id' => $admin->id]);
        }
    }

    public function test_allowlisted_metadata_preserves_public_writer_descriptors_and_rejects_containers(): void
    {
        $admin = $this->superAdmin();
        $account = Account::factory()->create();
        $safe = ['resource' => 'clients', 'resource_id' => 42, 'name' => 'Client Name', 'email' => 'public@example.com', 'document' => '11222333000181', 'role' => 'user', 'status' => 'active', 'person_type' => 'company', 'action' => 'attach', 'client_id' => 7, 'certificate_id' => null, 'previous_run_id' => 8, 'count' => 3, 'clients' => 2, 'tags' => 1, 'enabled' => true, 'power_refresh_queued' => false];
        $this->createLog($admin, $account, 'update', $safe + ['p12' => 'container-secret', 'certificate_bytes' => 'binary-secret', 'private_key' => 'private-secret', 'request_headers' => ['Authorization' => 'bearer-secret'], 0 => 'numeric-secret']);
        $this->actingAs($admin)->getJson('/api/v1/admin/support/logs')->assertOk()->assertJsonPath('data.0.metadata', $safe);
        $log = $this->createLog($admin, $account, 'update', ['resource' => ['body' => 'secret'], 'name' => ['password' => 'secret'], 'resource_id' => 'secret', 'enabled' => 'secret']);
        $this->getJson('/api/v1/admin/support/logs')->assertOk()->assertJsonPath('data.0.metadata', []);
        $log->update(['metadata' => null]);
        $this->getJson('/api/v1/admin/support/logs')->assertOk()->assertJsonPath('data.0.metadata', null);
    }

    public function test_additive_audit_migration_preserves_existing_rows_on_upgrade_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_17_230707_preserve_support_access_log_account_references.php');
        $migration->down();
        $admin = $this->superAdmin();
        $account = Account::factory()->create();
        $log = $this->createLog($admin, $account, 'legacy-action', ['resource' => 'clients', 'private_key' => 'stored-historical-secret']);
        $before = (array) DB::table('support_access_logs')->where('id', $log->id)->first();
        $migration->up();
        $this->assertSame($before, (array) DB::table('support_access_logs')->where('id', $log->id)->first());
        $migration->down();
        $this->assertSame($before, (array) DB::table('support_access_logs')->where('id', $log->id)->first());
        $migration->up();
        try {
            DB::transaction(fn () => $account->delete());
            $this->fail('Upgraded audit reference must prevent cascade deletion.');
        } catch (QueryException) {
            $this->assertDatabaseHas('support_access_logs', ['id' => $log->id, 'account_id' => $account->id]);
        }
    }

    public function test_audit_omits_unknown_token_like_and_oversized_resource_values_without_changing_history(): void
    {
        $admin = $this->superAdmin();
        $account = Account::factory()->create();
        $this->actingAs($admin);
        foreach (['Bearer historical-secret-value', 'unknown_resource', str_repeat('clients', 100)] as $resource) {
            $metadata = ['resource' => $resource, 'resource_id' => 42];
            $log = $this->createLog($admin, $account, 'update', $metadata);
            $this->getJson('/api/v1/admin/support/logs')->assertOk()->assertJsonPath('data.0.metadata', ['resource_id' => 42]);
            $this->assertSame($metadata, $log->fresh()->metadata);
        }
    }

    public function test_audit_preserves_all_resource_identifiers_emitted_by_current_writers(): void
    {
        $admin = $this->superAdmin();
        $account = Account::factory()->create();
        $this->actingAs($admin);
        foreach (['processes', 'members', 'departments', 'client_selections', 'clients', 'client_dispatch_contacts', 'fiscal', 'serpro_sync_runs', 'account_certificates', 'tags', 'client_saved_filters', 'serpro_manual_searches', 'serpro_obligation_schedules', 'serpro_monitorings', 'whatsapp_channels', 'serpro_enablement', 'serpro_messages', 'tasks', 'process_templates', 'whatsapp_dispatches'] as $resource) {
            $metadata = ['resource' => $resource, 'resource_id' => 42];
            $this->createLog($admin, $account, 'update', $metadata);
            $this->getJson('/api/v1/admin/support/logs')->assertOk()->assertJsonPath('data.0.metadata', $metadata);
        }
    }

    public function test_audit_requires_positive_integer_record_ids_while_preserving_zero_counts_and_nullable_certificate(): void
    {
        $admin = $this->superAdmin();
        $account = Account::factory()->create();
        $this->actingAs($admin);
        foreach ([0, null, -1, '42', true, 1.5, 42] as $resourceId) {
            $metadata = ['resource' => 'clients', 'resource_id' => $resourceId, 'count' => 0, 'clients' => 0, 'tags' => 0, 'certificate_id' => null];
            $log = $this->createLog($admin, $account, 'update', $metadata);
            $expected = ['resource' => 'clients', 'count' => 0, 'clients' => 0, 'tags' => 0, 'certificate_id' => null];
            if ($resourceId === 42) {
                $expected = $metadata;
            }
            $this->getJson('/api/v1/admin/support/logs')->assertOk()->assertJsonPath('data.0.metadata', $expected);
            $this->assertSame($metadata, $log->fresh()->metadata);
        }
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
