<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'business-test-key', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        Http::preventStrayRequests();
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'business-test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
    }

    private function login(string $role = 'admin', bool $superAdmin = false): array
    {
        $user = User::factory()->create(['is_super_admin' => $superAdmin]);
        $account = Account::factory()->create();
        $foreignAccount = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('current_account.id', $account->id);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'X-Account-Id' => (string) $account->id]);

        return [$user, $account, $foreignAccount];
    }

    private function clientPayload(): array
    {
        return ['person_type' => 'individual', 'tax_id' => '12345678909', 'name' => 'Integration Client', 'status' => 'active', 'tax_regime' => 'not_applicable'];
    }

    private function clientFor(Account $account, string $name): int
    {
        return DB::table('clients')->insertGetId([
            ...$this->clientPayload(), 'account_id' => $account->id, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_real_login_supports_client_crud_in_the_selected_office(): void
    {
        [, $account] = $this->login();
        $id = $this->postJson('/api/v1/clients', $this->clientPayload())->assertCreated()->json('data.id');
        $this->assertDatabaseHas('clients', ['id' => $id, 'account_id' => $account->id, 'name' => 'Integration Client']);
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/clients/'.$id)->assertOk()->assertJsonPath('data.name', 'Integration Client');
        $this->patchJson('/api/v1/clients/'.$id, ['name' => 'Updated Client'])->assertOk()->assertJsonPath('data.name', 'Updated Client');
        $this->deleteJson('/api/v1/clients/'.$id)->assertNoContent();
        $this->assertSoftDeleted('clients', ['id' => $id]);
    }

    public function test_foreign_client_binding_never_exposes_another_office(): void
    {
        [, $account, $foreignAccount] = $this->login();
        $own = $this->clientFor($account, 'Own Client');
        $foreign = $this->clientFor($foreignAccount, 'Foreign Client');
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        $this->getJson('/api/v1/clients/'.$foreign)->assertNotFound();
        $this->patchJson('/api/v1/clients/'.$foreign, ['name' => 'Stolen'])->assertNotFound();
        $this->deleteJson('/api/v1/clients/'.$foreign)->assertNotFound();
        $this->assertDatabaseHas('clients', ['id' => $foreign, 'name' => 'Foreign Client', 'deleted_at' => null]);
    }

    public function test_displayed_account_assertion_and_payload_cannot_switch_tenants(): void
    {
        [, $account, $foreignAccount] = $this->login();
        $this->withHeader('X-Account-Id', '')->getJson('/api/v1/clients')->assertUnprocessable();
        $this->withHeader('X-Account-Id', (string) $foreignAccount->id)->getJson('/api/v1/clients')->assertStatus(409)->assertJsonPath('code', 'account_context_changed');
        $this->withHeader('X-Account-Id', (string) $account->id)->postJson('/api/v1/clients', [
            ...$this->clientPayload(), 'account_id' => $foreignAccount->id,
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('clients', ['name' => 'Integration Client']);
    }

    public function test_super_admin_with_read_only_membership_cannot_write_tenant_data(): void
    {
        $this->login('user', true);
        $this->getJson('/api/v1/clients')->assertOk();
        $this->postJson('/api/v1/clients', $this->clientPayload())->assertForbidden();
        $this->postJson('/api/v1/tags', ['name' => 'Forbidden Tag', 'color' => 'primary'])->assertForbidden();
        $this->assertDatabaseMissing('clients', ['name' => 'Integration Client']);
    }

    public function test_revoked_and_suspended_memberships_stop_business_requests(): void
    {
        [$user, $account] = $this->login();
        $account->update(['status' => 'suspended']);
        $this->getJson('/api/v1/clients')->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $account->update(['status' => 'active']);
        AccountMembership::query()->where('user_id', $user->id)->delete();
        $this->getJson('/api/v1/clients')->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_business_writes_require_csrf_and_authenticated_session(): void
    {
        $this->getJson('/api/v1/clients')->assertUnauthorized();
        $this->login();
        $this->withHeader('X-CSRF-Token', 'invalid')->postJson('/api/v1/clients', $this->clientPayload())->assertForbidden();
        $this->assertDatabaseMissing('clients', ['name' => 'Integration Client']);
    }

    public function test_member_changes_preserve_the_last_account_administrator(): void
    {
        [$user] = $this->login();
        $this->patchJson('/api/v1/account/members/'.$user->id, ['role' => 'user'])->assertUnprocessable();
        $this->deleteJson('/api/v1/account/members/'.$user->id)->assertUnprocessable();
        $this->assertDatabaseHas('account_memberships', ['user_id' => $user->id, 'role' => 'admin']);
    }

    public function test_platform_commercial_updates_preserve_tenant_settings_and_access_secrets(): void
    {
        [$user, $account] = $this->login('admin', true);
        $account->update(['settings' => ['fiscal' => ['enabled' => true], 'platform' => ['owner_invite' => ['internal_marker' => 'private-test-value']]]]);
        AccountMembership::query()->where('user_id', $user->id)->delete();
        $path = '/api/v1/admin/accounts/'.$account->id.'/commercial';
        $this->patchJson($path, ['phone' => '11999999999', 'phone_whatsapp' => true, 'state' => 'sp', 'city' => 'São Paulo'])
            ->assertOk()->assertJsonPath('data.platform_billing_contact.state', 'SP')
            ->assertJsonPath('data.settings.fiscal.enabled', true)->assertJsonMissingPath('data.settings.platform');
        $this->assertSame(['internal_marker' => 'private-test-value'], $account->fresh()->settings['platform']['owner_invite']);
        $this->getJson($path)->assertOk()->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.owner_credentials');
        $this->patchJson($path, ['password' => 'Unauthorized credential change'])->assertUnprocessable();
        $this->assertSame('private-test-value', $account->fresh()->settings['platform']['owner_invite']['internal_marker']);
    }

    public function test_account_administrator_cannot_access_platform_commercial_administration(): void
    {
        [, $account] = $this->login();
        $path = '/api/v1/admin/accounts/'.$account->id.'/commercial';
        $this->getJson($path)->assertForbidden();
        $this->patchJson($path, ['city' => 'Unauthorized'])->assertForbidden();
        $this->assertNull($account->fresh()->platformBillingContact());
    }
}
