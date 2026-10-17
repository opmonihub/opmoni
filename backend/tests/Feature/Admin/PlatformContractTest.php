<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'platform-contract-key', 'access.origin' => 'http://localhost:3000', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'platform-contract-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    public static function platformReads(): array
    {
        return array_map(fn (string $path) => [$path], ['/admin/accounts', '/admin/plans', '/admin/subscriptions', '/admin/users', '/admin/support/logs', '/admin/summary']);
    }

    #[DataProvider('platformReads')]
    public function test_real_session_platform_authorization_without_office(string $path): void
    {
        $this->getJson('/api/v1'.$path)->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->login($admin);
        $this->getJson('/api/v1'.$path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('is_super_admin', true)->assertJsonPath('current_account', null);
        $this->getJson('/api/v1/clients')->assertForbidden();
    }

    #[DataProvider('platformReads')]
    public function test_real_session_ordinary_member_cannot_read_global_platform(string $path): void
    {
        $member = User::factory()->create();
        AccountMembership::factory()->create(['user_id' => $member->id, 'account_id' => Account::factory()->create()->id, 'role' => 'admin']);
        $this->login($member);
        $this->getJson('/api/v1'.$path)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_plan_mutations_require_real_authenticated_super_admin_session(): void
    {
        $plan = Plan::factory()->create(['name' => 'Original']);
        $token = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $token)->patchJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Denied'])->assertUnauthorized();
        $this->login(User::factory()->create());
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->patchJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Denied'])->assertForbidden();
        $this->assertSame('Original', $plan->fresh()->name);
    }

    public function test_plan_mutation_retains_ingress_origin_and_csrf_protection(): void
    {
        $plan = Plan::factory()->create(['name' => 'Original']);
        $this->login(User::factory()->create(['is_super_admin' => true]));
        $url = '/api/v1/admin/plans/'.$plan->id;
        $this->withHeader('X-Opmoni-Ingress-Key', 'invalid')->patchJson($url, ['name' => 'Rejected'])->assertForbidden();
        $this->withHeader('X-Opmoni-Ingress-Key', 'platform-contract-key')->withHeader('X-CSRF-Token', 'invalid')->patchJson($url, ['name' => 'Rejected'])->assertForbidden();
        $token = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders(['X-CSRF-Token' => $token, 'Origin' => 'https://hostile.example'])->patchJson($url, ['name' => 'Rejected'])->assertForbidden();
        $this->assertSame('Original', $plan->fresh()->name);
        $this->withHeader('Origin', 'http://localhost:3000')->patchJson($url, ['name' => 'Accepted'])->assertOk()->assertJsonPath('name', 'Accepted')->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson('/api/v1/admin/plans/999999', ['name' => 'Missing'])->assertNotFound();
        $this->getJson('/api/v1/admin/plans/999999')->assertNotFound();
    }

    public function test_real_session_audit_pagination_is_validated_and_retains_current_page(): void
    {
        $this->login(User::factory()->create(['is_super_admin' => true]));
        foreach (['0', '-1', '1.5', 'abc', '2147483648'] as $page) {
            $this->getJson('/api/v1/admin/support/logs?page='.$page)->assertUnprocessable()->assertJsonValidationErrors('page');
        }
        foreach (['0', '-1', '999999'] as $id) {
            $this->getJson('/api/v1/admin/support/logs?account_id='.$id)->assertUnprocessable()->assertJsonValidationErrors('account_id');
        }
        $this->getJson('/api/v1/admin/support/logs?page=2')->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonMissingPath('meta.page');
    }

    public function test_real_super_admin_membership_role_and_inactive_subscription_still_gate_tenant_writes(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $account = Account::factory()->create();
        $membership = AccountMembership::factory()->create(['user_id' => $admin->id, 'account_id' => $account->id, 'role' => 'user']);
        $this->login($admin);
        $this->withHeader('X-Account-Id', (string) $account->id);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->json('csrf_token'));
        $this->postJson('/api/v1/tags', ['name' => 'Denied'])->assertForbidden();
        $membership->update(['role' => 'admin']);
        $account->subscription->update(['status' => 'canceled']);
        $this->postJson('/api/v1/tags', ['name' => 'Denied'])->assertForbidden()->assertJsonPath('code', 'subscription_inactive');
        $this->getJson('/api/v1/tags')->assertOk();
        $this->assertDatabaseCount('tags', 0);
    }

    private function login(User $user): void
    {
        $token = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $token)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }
}
