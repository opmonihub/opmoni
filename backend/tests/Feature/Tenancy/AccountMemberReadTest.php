<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class AccountMemberReadTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_operador_lists_members(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'web')
            ->getJson('/api/v1/account/members')
            ->assertForbidden();
    }

    public function test_user_lists_members(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $user = $this->memberOf($account, 'user');

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/account/members')
            ->assertForbidden();
    }

    public function test_admin_and_support_manage_member_records(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');
        $target = $this->memberOf($account, 'user');

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/account/members')
            ->assertOk()
            ->assertJsonFragment(['email' => $target->email]);

        $this->actingAs($admin, 'web')
            ->getJson("/api/v1/account/members/{$target->getKey()}")
            ->assertOk()
            ->assertJsonPath('email', $target->email);
    }

    public function test_index_show_and_directory_share_read_ability(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');
        $user = $this->memberOf($account, 'user');

        foreach ([$operador, $user] as $member) {
            $this->actingAs($member, 'web');
            $this->getJson('/api/v1/account/members')->assertForbidden();
            $this->getJson("/api/v1/account/members/{$user->getKey()}")->assertForbidden();
            $this->getJson('/api/v1/account/members/directory')->assertOk();
        }
    }

    public function test_show_cross_account_returns_404(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $other = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $foreign = $this->memberOf($other, 'admin');

        $this->actingAs($admin, 'web')
            ->getJson("/api/v1/account/members/{$foreign->getKey()}")
            ->assertNotFound();
    }

    public function test_writes_still_require_admin(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'web')
            ->postJson('/api/v1/account/members', [
                'name' => 'Convidado',
                'email' => 'convidado@opmoni.dev',
                'password' => 'password123',
                'role' => 'user',
            ])
            ->assertForbidden();
    }

    public function test_new_member_email_is_normalized_and_can_authenticate(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $this->actingAs($admin)->postJson('/api/v1/account/members', [
            'name' => 'Ana', 'email' => ' ANA@example.com ', 'password' => 'member-password', 'role' => 'user',
        ])->assertCreated()->assertJsonPath('email', 'ana@example.com');
        $csrf = $this->getJson('/api/v1/csrf')->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', [
            'email' => 'ANA@example.com', 'password' => 'member-password',
        ])->assertOk()->assertJsonPath('email', 'ana@example.com');
    }

    public function test_duplicate_email_is_rejected_after_normalization(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        User::factory()->create(['email' => 'ana@example.com']);
        $count = User::count();
        $this->actingAs($admin)->postJson('/api/v1/account/members', [
            'name' => 'Ana', 'email' => 'ANA@example.com', 'password' => 'member-password', 'role' => 'user',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($count, User::count());
    }

    public function test_member_password_above_login_limit_is_rejected_without_identity(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $this->actingAs($admin)->postJson('/api/v1/account/members', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'password' => str_repeat('a', 129), 'role' => 'user',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
