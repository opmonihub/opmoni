<?php

namespace Tests\Feature\Admin;

use App\Actions\AccessAccounts;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PlatformSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require glob(database_path('migrations/*_add_subscription_status_constraint.php'))[0];
    }

    public function test_additive_status_constraint_upgrade_and_rollback_preserve_existing_records(): void
    {
        [$account] = app(AccessAccounts::class)->issue(['name' => 'Existing', 'owner_name' => 'Pending', 'login_email' => 'pending@example.test']);
        $account->update(['settings' => ['platform' => ['billing_contact' => ['phone' => '111']], 'custom' => true]]);
        AccountMembership::factory()->create(['account_id' => $account->id]);
        $account->subscription()->update(['status' => 'past_due']);
        $tables = ['accounts', 'account_memberships', 'users', 'owner_activations', 'subscriptions', 'plans'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        $migration = $this->migration();
        $migration->down();
        $migration->up();
        $migration->down();
        $migration->up();
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get()->all(), $table);
        }
    }

    public function test_database_rejects_invalid_subscription_status_on_update(): void
    {
        $account = Account::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->where('account_id', $account->id)->update(['status' => 'trial']);
    }

    public function test_database_rejects_invalid_subscription_status_on_insert(): void
    {
        $this->expectException(QueryException::class);
        Subscription::factory()->create(['status' => 'invalid']);
    }

    public function test_migration_reports_invalid_legacy_rows_without_rewriting_them(): void
    {
        $account = Account::factory()->create();
        $migration = $this->migration();
        $migration->down();
        DB::table('subscriptions')->where('account_id', $account->id)->update(['status' => 'legacy']);
        $before = DB::table('subscriptions')->where('account_id', $account->id)->first();
        $failure = null;
        try {
            $migration->up();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertStringContainsString((string) $before->id, $failure->getMessage());
        $this->assertEquals($before, DB::table('subscriptions')->where('account_id', $account->id)->first());
    }

    public function test_unique_account_subscription_is_preserved(): void
    {
        $account = Account::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->insert(['account_id' => $account->id, 'plan_id' => $account->subscription->plan_id, 'status' => 'active']);
    }

    public function test_plan_foreign_key_is_preserved(): void
    {
        $account = Account::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->where('account_id', $account->id)->update(['plan_id' => 999999]);
    }

    public function test_subscribed_plan_cannot_be_deleted(): void
    {
        $account = Account::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('plans')->where('id', $account->subscription->plan_id)->delete();
    }
}
