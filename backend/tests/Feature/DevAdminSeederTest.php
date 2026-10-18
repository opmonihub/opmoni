<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_seed_creates_a_usable_admin_and_account_only_once(): void
    {
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->sole();
        $account = Account::sole();

        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame($account->id, $admin->defaultAccountId());
        $this->assertSame('admin', $admin->accountRole($account));
        $this->assertSame(1, User::count());
        $this->assertSame(1, AccountMembership::count());
        $this->assertSame(4, app(CurrentTenant::class)->run($account->id, fn (): int => $account->departments()->count()));
        $this->assertNotNull($account->subscription);
    }

    public function test_reuses_the_first_active_account_and_existing_admin_without_changing_other_memberships(): void
    {
        $this->app['env'] = 'local';
        $suspended = Account::factory()->create(['status' => 'suspended']);
        $first = Account::factory()->create();
        $other = Account::factory()->create();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        AccountMembership::create(['account_id' => $first->id, 'user_id' => $admin->id, 'role' => 'member']);
        AccountMembership::create(['account_id' => $other->id, 'user_id' => $admin->id, 'role' => 'member']);

        $this->seed(DevAdminSeeder::class);
        $this->seed(DevAdminSeeder::class);

        $admin->refresh();
        $this->assertSame(1, User::count());
        $this->assertSame(3, Account::count());
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame($first->id, $admin->defaultAccountId());
        $this->assertSame('admin', $admin->accountRole($first));
        $this->assertSame('member', $admin->accountRole($other));
        $this->assertNull($admin->accountRole($suspended));
        $this->assertSame(2, AccountMembership::count());
    }

    public function test_non_local_seed_does_not_create_or_modify_access(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'password' => 'keep-existing-password']);

        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->app['env'] = $environment;
            (new DatabaseSeeder)->run();
            (new DevAdminSeeder)->run();
        }

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('keep-existing-password', $admin->fresh()->password));
        $this->assertFalse($admin->fresh()->isSuperAdmin());
        $this->assertSame(0, Account::count());
        $this->assertSame(0, AccountMembership::count());
    }
}
