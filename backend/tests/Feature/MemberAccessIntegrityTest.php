<?php

namespace Tests\Feature;

use App\Actions\AccessAccounts;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Department;
use App\Models\OwnerActivation;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Services\DepartmentMembership;
use App\Services\ProcessGenerationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BusinessTestCase;

class MemberAccessIntegrityTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_member_creation_preserves_another_accounts_live_owner_reservation(): void
    {
        $account = Account::factory()->create();
        $admin = $this->adminOf($account);
        [$pending, $link] = app(AccessAccounts::class)->issue([
            'name' => 'Pending office', 'owner_name' => 'Pending owner', 'login_email' => 'reserved@example.test',
        ]);
        $this->actingAs($admin)->postJson('/api/v1/account/members', $this->memberPayload(' RESERVED@example.test '))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'reserved@example.test']);
        $this->assertDatabaseHas('owner_activations', ['account_id' => $pending->id, 'consumed_at' => null, 'revoked_at' => null]);
        parse_str(parse_url($link['url'], PHP_URL_QUERY), $credential);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/first-access', [
            ...$credential, 'password' => 'owner-password', 'password_confirmation' => 'owner-password',
        ])->assertCreated()->assertJsonPath('current_account.id', $pending->id)->assertJsonPath('current_account.role', 'admin');
    }

    public function test_inactive_owner_reservations_do_not_block_member_creation(): void
    {
        $account = Account::factory()->create();
        $admin = $this->adminOf($account);
        foreach (['expired', 'revoked', 'consumed'] as $state) {
            $email = $state.'@example.test';
            OwnerActivation::factory()->create([
                'email' => $email,
                ...match ($state) {
                    'expired' => ['expires_at' => now()->subSecond()],
                    'revoked' => ['revoked_at' => now()],
                    'consumed' => ['consumed_at' => now()],
                },
            ]);
            $this->actingAs($admin)->postJson('/api/v1/account/members', $this->memberPayload($email))->assertCreated();
        }
    }

    public function test_identity_claim_is_rechecked_after_the_email_lock(): void
    {
        $account = Account::factory()->create();
        $admin = $this->adminOf($account);
        $claimant = null;
        $actions = \Mockery::mock(AccessAccounts::class)->makePartial();
        $actions->shouldReceive('advisory')->once()->with('email:claimed@example.test')
            ->andReturnUsing(function () use (&$claimant): void {
                $claimant = User::factory()->create(['email' => 'claimed@example.test']);
            });
        $this->app->instance(AccessAccounts::class, $actions);

        $this->actingAs($admin)->postJson('/api/v1/account/members', $this->memberPayload('claimed@example.test'))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotNull($claimant);
        $this->assertDatabaseCount('account_memberships', 1);
        $this->assertDatabaseMissing('account_memberships', ['user_id' => $claimant->id]);
    }

    public function test_owner_reservation_is_rechecked_after_the_email_lock(): void
    {
        $account = Account::factory()->create();
        $pending = Account::factory()->create();
        $admin = $this->adminOf($account);
        $actions = \Mockery::mock(AccessAccounts::class)->makePartial();
        $actions->shouldReceive('advisory')->once()->with('email:pending@example.test')
            ->andReturnUsing(function () use ($pending): void {
                OwnerActivation::factory()->create(['account_id' => $pending->id, 'email' => 'pending@example.test']);
            });
        $this->app->instance(AccessAccounts::class, $actions);

        $this->actingAs($admin)->postJson('/api/v1/account/members', $this->memberPayload('pending@example.test'))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'pending@example.test']);
        $this->assertDatabaseCount('account_memberships', 1);
    }

    public function test_busy_email_lock_is_rejected_without_waiting_or_writing(): void
    {
        $account = Account::factory()->create();
        $admin = $this->adminOf($account);
        $this->actingAs($admin);
        $actions = \Mockery::mock(AccessAccounts::class)->makePartial();
        $actions->shouldNotReceive('advisory');
        $this->app->instance(AccessAccounts::class, $actions);
        $database = \Mockery::mock(DB::getFacadeRoot());
        $database->shouldReceive('getDriverName')->andReturn('pgsql');
        $database->shouldReceive('statement')->with("SET LOCAL lock_timeout = '2s'")->andReturn(true);
        $database->shouldReceive('statement')->with("SET LOCAL statement_timeout = '5s'")->andReturn(true);
        $database->shouldReceive('selectOne')->once()
            ->with('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', ['email:busy@example.test'])
            ->andReturn((object) ['acquired' => false]);
        DB::swap($database);

        $this->postJson('/api/v1/account/members', $this->memberPayload('busy@example.test'))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'busy@example.test']);
        $this->assertDatabaseCount('account_memberships', 1);
    }

    public function test_late_email_unique_collision_is_a_validation_error_with_full_rollback(): void
    {
        $account = Account::factory()->create();
        $admin = $this->adminOf($account);
        $collision = null;
        $listener = function (User $user) use (&$collision): void {
            if ($user->email === 'racing@example.test') {
                $collision = User::withoutEvents(fn () => User::factory()->create(['email' => $user->email]));
            }
        };
        User::creating($listener);

        $this->actingAs($admin)->postJson('/api/v1/account/members', $this->memberPayload('racing@example.test'))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotNull($collision);
        $this->assertDatabaseMissing('users', ['email' => 'racing@example.test']);
        $this->assertDatabaseCount('account_memberships', 1);
    }

    public function test_unrelated_unique_collision_is_not_reported_as_an_email_conflict(): void
    {
        $account = Account::factory()->create();
        $admin = $this->adminOf($account);
        User::creating(function (User $user) use ($admin): void {
            $user->id = $admin->id;
        });

        $this->actingAs($admin)->postJson('/api/v1/account/members', $this->memberPayload('unrelated@example.test'))
            ->assertStatus(503)->assertJsonMissingPath('errors.email');
        $this->assertDatabaseMissing('users', ['email' => 'unrelated@example.test']);
        $this->assertDatabaseCount('account_memberships', 1);
    }

    public function test_removing_membership_cleans_only_its_account_department_links(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $admin = $this->adminOf($account);
        $member = User::factory()->create();
        foreach ([$account, $other] as $office) {
            AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $member->id, 'role' => 'user']);
            $department = Department::withoutGlobalScopes()->where('account_id', $office->id)->where('name', 'Fiscal')->sole();
            $department->members()->attach($member->id, ['account_id' => $office->id]);
        }

        $this->actingAs($admin)->deleteJson('/api/v1/account/members/'.$member->id)->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('department_user', ['account_id' => $account->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('department_user', ['account_id' => $other->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('account_memberships', ['account_id' => $other->id, 'user_id' => $member->id]);
        $fiscal = collect($this->getJson('/api/v1/departments')->assertOk()->json('data'))->firstWhere('name', 'Fiscal');
        $this->assertSame(0, $fiscal['members_count']);
    }

    public function test_department_membership_rejects_orphaned_and_foreign_department_links(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $user = User::factory()->create();
        $department = Department::withoutGlobalScopes()->where('account_id', $account->id)->firstOrFail();
        $department->members()->attach($user->id, ['account_id' => $account->id]);
        $this->assertFalse(DepartmentMembership::memberBelongs($account->id, $department->id, $user->id));

        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
        $this->assertTrue(DepartmentMembership::memberBelongs($account->id, $department->id, $user->id));
        AccountMembership::factory()->create(['account_id' => $other->id, 'user_id' => $user->id]);
        DB::table('department_user')->where('department_id', $department->id)->update(['account_id' => $other->id]);
        $this->assertFalse(DepartmentMembership::memberBelongs($other->id, $department->id, $user->id));
    }

    public function test_generation_discards_removed_assignees_even_without_a_department(): void
    {
        $account = Account::factory()->create();
        $admin = $this->adminOf($account);
        $removed = User::factory()->create();
        $membership = AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $removed->id]);
        $membership->delete();
        $this->actingAs($admin);
        $department = Department::where('name', 'Fiscal')->sole();
        $department->members()->attach($removed->id, ['account_id' => $account->id]);
        $template = ProcessTemplate::factory()->create(['account_id' => $account->id, 'regimes' => []]);
        foreach ([null, $department->id] as $order => $departmentId) {
            $template->steps()->create([
                'title' => 'Removed assignee '.$order, 'department_id' => $departmentId,
                'due_day' => 5, 'priority' => 'medium', 'order' => $order,
                'default_assignee_member_id' => $removed->id,
            ]);
        }
        $template->steps()->create([
            'title' => 'Current member', 'department_id' => null,
            'due_day' => 5, 'priority' => 'medium', 'order' => 2,
            'default_assignee_member_id' => $admin->id,
        ]);
        Client::factory()->company()->create(['account_id' => $account->id, 'status' => 'active']);

        $process = app(ProcessGenerationService::class)->generate($template, Carbon::create(2027, 2, 1))->sole();
        $tasks = $process->tasks()->ordered()->get();
        $this->assertCount(3, $tasks);
        $this->assertNull($tasks[0]->assignee_member_id);
        $this->assertNull($tasks[1]->assignee_member_id);
        $this->assertSame($admin->id, $tasks[2]->assignee_member_id);
    }

    private function adminOf(Account $account): User
    {
        $admin = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $admin->id, 'role' => 'admin']);
        $this->selectBusinessAccount($admin, $account->id);

        return $admin;
    }

    /** @return array{name: string, email: string, password: string, role: string} */
    private function memberPayload(string $email): array
    {
        return ['name' => 'New member', 'email' => $email, 'password' => 'member-password', 'role' => 'user'];
    }
}
