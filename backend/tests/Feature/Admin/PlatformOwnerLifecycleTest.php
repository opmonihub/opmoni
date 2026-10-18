<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\OwnerActivation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformOwnerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'owner-lifecycle-test', 'access.origin' => 'http://localhost:3000', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'owner-lifecycle-test', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    public function test_pending_owner_selects_latest_live_link_ignoring_newer_inactive_links(): void
    {
        $this->login(User::factory()->create(['is_super_admin' => true]));
        $account = Account::factory()->create();
        OwnerActivation::factory()->create(['account_id' => $account->id, 'email' => 'earlier@example.test', 'revoked_at' => now()]);
        $live = OwnerActivation::factory()->create(['account_id' => $account->id, 'email' => 'latest-live@example.test']);
        foreach ([['expires_at' => now()->subSecond(), 'revoked_at' => now()], ['revoked_at' => now()], ['consumed_at' => now()]] as $state) {
            OwnerActivation::factory()->create(['account_id' => $account->id, ...$state]);
        }
        foreach (['/api/v1/admin/accounts/'.$account->id => 'data.pending_owner', '/api/v1/admin/accounts' => 'data.0.pending_owner'] as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonPath($path.'.email', $live->email)
                ->assertJsonPath($path.'.expires_at', $live->expires_at->toISOString());
        }
        $live->update(['revoked_at' => now()]);
        OwnerActivation::factory()->create(['account_id' => $account->id, 'expires_at' => now()->subSecond()]);
        $this->getJson('/api/v1/admin/accounts/'.$account->id)->assertOk()->assertJsonPath('data.pending_owner', null);
    }

    public function test_issue_and_reissue_expire_in_seven_days_and_activation_preserves_existing_identity(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = User::factory()->create(['is_super_admin' => true]);
        $existing = $admin->fresh()->getRawOriginal();
        $this->login($admin);
        $payload = ['name' => 'Lifecycle Office', 'owner_name' => 'New Owner', 'login_email' => 'lifecycle-owner@example.test'];
        $issued = $this->postJson('/api/v1/admin/accounts', $payload)->assertCreated()
            ->assertJsonPath('first_access.expires_at', now()->addDays(7)->toISOString());
        $id = $issued->json('data.id');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseEmpty('account_memberships');
        $old = OwnerActivation::sole();
        $this->travel(2)->hours();
        $reissued = $this->postJson('/api/v1/admin/accounts/'.$id.'/first-access', array_diff_key($payload, ['name' => true]))->assertOk()
            ->assertJsonPath('first_access.expires_at', now()->addDays(7)->toISOString());
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseEmpty('account_memberships');
        parse_str(parse_url($reissued->json('first_access.url'), PHP_URL_QUERY), $credential);
        $this->postJson('/api/v1/first-access', [...$credential, 'password' => 'new-owner-password', 'password_confirmation' => 'new-owner-password'])
            ->assertCreated()->assertJsonPath('is_super_admin', false)->assertJsonPath('current_account.role', 'admin');
        $owner = User::where('email', $payload['login_email'])->sole();
        $this->assertTrue(Hash::check('new-owner-password', $owner->password));
        $this->assertNotNull(OwnerActivation::where('account_id', $id)->latest('id')->firstOrFail()->consumed_at);
        $this->assertTrue($existing === $admin->fresh()->getRawOriginal(), 'Existing identity and credentials must remain unchanged.');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('account_memberships', 1);
    }

    public function test_suspended_reissue_and_activation_leave_links_and_credentials_unchanged(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->login($admin);
        $payload = ['name' => 'Suspended Office', 'owner_name' => 'Pending', 'login_email' => 'suspended-owner@example.test'];
        $issued = $this->postJson('/api/v1/admin/accounts', $payload)->assertCreated();
        $id = $issued->json('data.id');
        $this->patchJson('/api/v1/admin/accounts/'.$id, ['status' => 'suspended'])->assertOk();
        $before = OwnerActivation::sole()->getRawOriginal();
        $identity = $admin->fresh()->getRawOriginal();
        $this->postJson('/api/v1/admin/accounts/'.$id.'/first-access', array_diff_key($payload, ['name' => true]))->assertForbidden()->assertJsonPath('code', 'account_suspended');
        parse_str(parse_url($issued->json('first_access.url'), PHP_URL_QUERY), $credential);
        $this->postJson('/api/v1/first-access', [...$credential, 'password' => 'owner-password', 'password_confirmation' => 'owner-password'])->assertUnprocessable();
        $this->assertTrue($before === OwnerActivation::sole()->getRawOriginal(), 'Rejected activation must preserve the pending link.');
        $this->assertTrue($identity === $admin->fresh()->getRawOriginal(), 'Rejected activation must preserve existing credentials.');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseEmpty('account_memberships');
    }

    public function test_switching_account_preserves_absolute_access_deadline(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $accounts = Account::factory()->count(2)->create();
        foreach ($accounts as $account) {
            AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
        }
        $this->login($user);
        $deadline = time() + 600;
        foreach ($accounts->reverse() as $account) {
            $this->withSession(['access_deadline' => $deadline])->postJson('/api/v1/account/switch', ['account_id' => $account->id])
                ->assertOk()->assertJsonPath('current_account.id', $account->id)->assertSessionHas('access_deadline', $deadline);
            $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        }
    }

    public function test_canonical_account_routes_preserve_names_private_responses_and_mutation_guards(): void
    {
        $this->login(User::factory()->create(['is_super_admin' => true]));
        $account = Account::factory()->create(['name' => 'Protected office']);
        foreach ([
            'access.admin-list' => ['/api/v1/admin/accounts', []],
            'access.admin-create' => ['/api/v1/admin/accounts', []],
            'access.admin-show' => ['/api/v1/admin/accounts/'.$account->id, ['id' => $account->id]],
            'access.admin-update' => ['/api/v1/admin/accounts/'.$account->id, ['id' => $account->id]],
            'access.admin-reissue' => ['/api/v1/admin/accounts/'.$account->id.'/first-access', ['id' => $account->id]],
            'access.admin-commercial' => ['/api/v1/admin/accounts/'.$account->id.'/commercial', ['account' => $account->id]],
            'access.admin-commercial-update' => ['/api/v1/admin/accounts/'.$account->id.'/commercial', ['account' => $account->id]],
        ] as $name => [$path, $parameters]) {
            $this->assertSame($path, route($name, $parameters, false));
        }
        foreach (['/api/v1/admin/accounts', '/api/v1/admin/accounts/'.$account->id, '/api/v1/admin/accounts/'.$account->id.'/commercial'] as $path) {
            $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        foreach ([
            ['POST', '/api/v1/admin/accounts', ['name' => 'Rejected', 'owner_name' => 'Pending', 'login_email' => 'rejected@example.test']],
            ['PATCH', '/api/v1/admin/accounts/'.$account->id, ['name' => 'Rejected']],
            ['PATCH', '/api/v1/admin/accounts/'.$account->id.'/commercial', ['city' => 'Rejected']],
            ['POST', '/api/v1/admin/accounts/'.$account->id.'/first-access', ['owner_name' => 'Pending', 'login_email' => 'rejected@example.test']],
        ] as [$method, $path, $payload]) {
            $token = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
            $this->withHeader('X-CSRF-Token', '')->json($method, $path, $payload)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
            $this->withHeaders(['X-CSRF-Token' => $token, 'Origin' => 'https://hostile.example'])->json($method, $path, $payload)->assertForbidden();
            $this->withHeader('Origin', 'http://localhost:3000');
        }
        $this->assertSame('Protected office', $account->fresh()->name);
        $this->assertNull($account->fresh()->platformBillingContact());
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseEmpty('owner_activations');
    }

    private function login(User $user): void
    {
        $token = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $token)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
    }
}
