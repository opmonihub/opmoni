<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\BusinessTestCase;

class AccountMemberMutationLockOrderTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_account_member_role_mutation_releases_preflight_account_before_locking_user_then_account(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $target = $this->memberOf($account, 'user');
        self::assertNotNull(Route::getRoutes()->getByName('members.update'));
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $sql = strtolower($event->sql);
            if (str_contains($sql, ' from "accounts"') || str_contains($sql, ' from "users"') || str_contains($sql, ' from `accounts`') || str_contains($sql, ' from `users`')) {
                $queries[] = ['sql' => $sql, 'transaction_level' => DB::transactionLevel()];
            }
        });

        $this->actingAs($admin, 'web')->patchJson('/api/v1/account/members/'.$target->id, ['role' => 'operador'])
            ->assertOk()->assertJsonPath('role', 'operador');

        $transactional = array_values(array_filter($queries, static fn (array $query): bool => $query['transaction_level'] > 0));
        $userLockIndex = null;
        $postIdentityAccountLockIndex = null;
        foreach ($transactional as $index => $query) {
            if ($userLockIndex === null && str_contains($query['sql'], 'from "users"') && str_contains($query['sql'], ' in (')) {
                $userLockIndex = $index;
            }
            if ($userLockIndex !== null && str_contains($query['sql'], 'from "accounts"') && str_contains($query['sql'], 'id" =')) {
                $postIdentityAccountLockIndex = $index;
                break;
            }
        }
        self::assertNotNull($userLockIndex, 'The mutation transaction must lock actor and target identities.');
        self::assertNotNull($postIdentityAccountLockIndex, 'The mutation transaction must re-lock the Account after identities. '.json_encode($transactional, JSON_THROW_ON_ERROR));
        self::assertGreaterThan($userLockIndex, $postIdentityAccountLockIndex);
        $this->assertDatabaseHas('account_memberships', ['account_id' => $account->id, 'user_id' => $target->id, 'role' => 'operador']);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }
}
