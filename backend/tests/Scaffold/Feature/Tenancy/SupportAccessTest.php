<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Plan;
use App\Models\SupportAccessLog;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_super_admin_enter_sets_current_account_and_logs(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertOk();

        $this->assertSame($target->getKey(), $superAdmin->refresh()->current_account_id);
        $this->assertDatabaseHas('support_access_logs', [
            'super_admin_user_id' => $superAdmin->getKey(),
            'account_id' => $target->getKey(),
            'action' => 'enter',
        ]);
        $this->assertNotNull(SupportAccessLog::firstWhere('action', 'enter')->ip);
    }

    public function test_super_admin_enter_own_account_creates_no_log(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $homeId = $superAdmin->current_account_id;

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$homeId}/enter")
            ->assertOk();

        $this->assertSame($homeId, $superAdmin->refresh()->current_account_id);
        $this->assertSame(0, SupportAccessLog::count());
    }

    public function test_super_admin_reenter_same_foreign_account_creates_no_duplicate_log(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertOk();

        $this->assertSame($target->getKey(), $superAdmin->refresh()->current_account_id);
        $this->assertSame(1, SupportAccessLog::where('action', 'enter')->count());
    }

    public function test_super_admin_exit_restores_own_account_and_logs(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $homeId = $superAdmin->current_account_id;
        $target = Account::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/support/exit')
            ->assertOk();

        $this->assertSame($homeId, $superAdmin->refresh()->current_account_id);
        $this->assertDatabaseHas('support_access_logs', [
            'super_admin_user_id' => $superAdmin->getKey(),
            'account_id' => $target->getKey(),
            'action' => 'exit',
        ]);
    }

    public function test_exit_outside_support_mode_returns_422(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/support/exit')
            ->assertUnprocessable();

        $this->assertSame(0, SupportAccessLog::count());
    }

    public function test_support_writes_apply_and_log_with_super_admin_identity(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertOk();

        $clientId = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/clients', $this->individualClientPayload(['name' => 'Cliente Suporte']))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/clients/{$clientId}", ['name' => 'Cliente Suporte Editado'])
            ->assertOk();

        $this->assertSame('Cliente Suporte Editado', Client::withoutGlobalScopes()->find($clientId)->name);

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/clients/{$clientId}")
            ->assertNoContent();

        $this->assertSoftDeleted('clients', ['id' => $clientId]);

        foreach (['create', 'update', 'delete'] as $action) {
            $this->assertDatabaseHas('support_access_logs', [
                'super_admin_user_id' => $superAdmin->getKey(),
                'account_id' => $target->getKey(),
                'action' => $action,
            ]);
        }

        $log = SupportAccessLog::firstWhere(['action' => 'create', 'account_id' => $target->getKey()]);
        $this->assertSame('clients', $log->metadata['resource']);
        $this->assertSame($clientId, $log->metadata['resource_id']);
    }

    public function test_super_admin_write_in_own_account_does_not_log(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $homeId = $superAdmin->current_account_id;

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/clients', $this->individualClientPayload(['name' => 'Cliente Próprio']))
            ->assertCreated();

        $this->assertSame(0, SupportAccessLog::count());
        $this->assertSame($homeId, $superAdmin->refresh()->current_account_id);
    }

    public function test_regular_user_cannot_use_support_routes(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $target = Account::factory()->create();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/support/exit')
            ->assertForbidden();

        $this->assertSame($account->getKey(), $member->refresh()->current_account_id);
        $this->assertSame(0, SupportAccessLog::count());
    }

    public function test_suspend_account_via_admin_blocks_tenant_until_reactivated(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        Client::factory()->create(['account_id' => $account->getKey()]);

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/admin/accounts/{$account->getKey()}", ['status' => 'suspended'])
            ->assertOk();

        $this->assertSame('suspended', $account->refresh()->status);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/clients')
            ->assertForbidden();

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/admin/accounts/{$account->getKey()}", ['status' => 'active'])
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_plan_change_via_admin_applies_limits_immediately(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');

        Client::factory()->count(50)->create(['account_id' => $account->getKey()]);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/clients', $this->individualClientPayload())
            ->assertUnprocessable();

        $subscriptionId = $account->subscription->getKey();

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/admin/subscriptions/{$subscriptionId}", [
                'plan_id' => Plan::bySlug('empresarial')->getKey(),
            ])
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/clients', $this->individualClientPayload(['name' => 'Cliente 51']))
            ->assertCreated();

        $this->assertSame(51, $account->clients()->count());
    }

    public function test_me_lists_non_member_accounts_for_super_admin_switcher(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $other = Account::factory()->create(['name' => 'Cliente Beta']);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/me')
            ->assertOk();

        $beta = collect($response->json('accounts'))->firstWhere('name', 'Cliente Beta');
        $this->assertIsArray($beta);
        $this->assertFalse($beta['is_member']);
        $this->assertArrayNotHasKey('role', $beta);
    }

    public function test_super_admin_switch_to_any_account_succeeds_and_is_audit_visible(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/account/switch', ['account_id' => $target->getKey()])
            ->assertOk();

        $this->assertSame($target->getKey(), $superAdmin->refresh()->current_account_id);
        $this->assertDatabaseHas('support_access_logs', [
            'super_admin_user_id' => $superAdmin->getKey(),
            'account_id' => $target->getKey(),
            'action' => 'enter',
        ]);
    }

    public function test_admin_panel_endpoints_serve_super_admin(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $account = Account::factory()->create();
        $this->memberOf($account, 'admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/accounts')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'basico']);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/plans')
            ->assertOk()
            ->assertJsonCount(3);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$account->getKey()}/enter")
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/support/logs')
            ->assertOk()
            ->assertJsonFragment(['action' => 'enter']);
    }

    /** @return array<string, string> */
    private function individualClientPayload(array $overrides = []): array
    {
        return array_replace([
            'person_type' => 'individual',
            'tax_id' => '52998224725',
            'name' => 'Cliente Teste',
            'status' => 'active',
            'tax_regime' => 'not_applicable',
        ], $overrides);
    }

    private function superAdminWithOwnAccount(): User
    {
        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $home = Account::factory()->create();

        AccountUser::create([
            'account_id' => $home->getKey(),
            'user_id' => $superAdmin->getKey(),
            'role' => 'admin',
        ]);

        $superAdmin->forceFill(['current_account_id' => $home->getKey()])->save();

        return $superAdmin->refresh();
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();

        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
