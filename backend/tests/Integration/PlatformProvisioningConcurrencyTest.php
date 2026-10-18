<?php

namespace Tests\Integration;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Department;
use App\Models\OwnerActivation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class PlatformProvisioningConcurrencyTest extends PlatformProcessTestCase
{
    public static function winners(): array
    {
        return ['initializer first' => ['initialize'], 'issuance first' => ['issue']];
    }

    #[DataProvider('winners')]
    public function test_two_initializers_and_existing_issuance_preserve_catalog_and_unrelated_state(string $winner): void
    {
        app(PlanCatalogSeeder::class)->run();
        foreach (Plan::all() as $plan) {
            $plan->update(['name' => 'Edited '.$plan->slug, 'limits' => ['users' => 17, 'custom_limit' => 3]]);
        }
        $missing = Account::withoutEvents(fn () => Account::factory()->create());
        $other = Account::factory()->create();
        $pastDue = Account::factory()->create();
        $other->subscription->update(['status' => 'canceled', 'plan_id' => Plan::bySlug('profissional')->id]);
        $pastDue->subscription->update(['status' => 'past_due', 'plan_id' => Plan::bySlug('empresarial')->id]);
        AccountMembership::factory()->create(['account_id' => $other->id]);
        $this->activation($other, 'unrelated@example.test');
        $before = $this->baseline();
        $subscriptions = Subscription::orderBy('id')->get()->map->getAttributes()->all();
        $first = $this->worker($winner, ['account' => $missing->id, 'email' => 'issued@example.test', 'pause' => 'account']);
        $this->waitFor($first, 'held');
        $this->assertGreaterThan(0, $this->signalData($first, 'held')['transaction_level']);
        $second = $this->worker('initialize');
        $third = $this->worker($winner === 'initialize' ? 'issue' : 'initialize', ['account' => $missing->id, 'email' => 'issued@example.test']);
        $this->assertWaiting($second, 'accounts');
        $this->assertWaiting($third, 'accounts');
        $this->release($first);
        $this->assertSame($winner === 'issue' ? 201 : 200, $this->workerResult($first)['status']);
        $this->assertSame(200, $this->workerResult($second)['status']);
        $this->assertSame($winner === 'initialize' ? 201 : 200, $this->workerResult($third)['status']);
        $this->assertSame(Account::count(), Subscription::count());
        $this->assertSame([], DB::table('subscriptions')->select('account_id')->groupBy('account_id')->havingRaw('count(*) > 1')->get()->all());
        foreach ($subscriptions as $subscription) {
            $this->assertSame($subscription, Subscription::findOrFail($subscription['id'])->getAttributes());
        }
        $this->assertSame($before['plans'], Plan::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(3, Plan::distinct()->count('slug'));
        $this->assertTrue($before['users'] === User::orderBy('id')->get()->map->getAttributes()->all(), 'Existing identities changed.');
        $this->assertEquals($before['memberships'], DB::table('account_memberships')->orderBy('id')->get()->all());
        $this->assertSame($before['departments'], Department::withoutGlobalScopes()->count());
        $this->assertTrue($before['activations'][0] === OwnerActivation::findOrFail($before['activations'][0]['id'])->getAttributes(), 'Unrelated activation changed.');
        $this->assertSame(1, OwnerActivation::where('account_id', $missing->id)->whereNull('revoked_at')->whereNull('consumed_at')->count());
        $this->assertSame('basico', $missing->fresh()->subscription->plan->slug);
        $this->assertSame('active', $missing->fresh()->subscription->status);
    }

    public function test_new_issuance_is_provisioned_after_initializer_has_selected_its_chunk(): void
    {
        Account::withoutEvents(fn () => Account::factory()->create());
        $initializer = $this->worker('initialize', ['pause' => 'scan']);
        $this->waitFor($initializer, 'held');
        $issued = $this->workerResult($this->worker('issue', ['email' => 'new@example.test']));
        $this->assertSame(201, $issued['status']);
        $account = Account::findOrFail($issued['account']);
        $this->assertSame('active', $account->subscription->status);
        $this->assertSame('basico', $account->subscription->plan->slug);
        $this->assertSame(['Contábil', 'Fiscal', 'Pessoal', 'Societário'], Department::withoutGlobalScopes()->where('account_id', $account->id)->orderBy('name')->pluck('name')->all());
        $this->release($initializer);
        $this->assertSame(200, $this->workerResult($initializer)['status']);
        $this->assertSame(2, Subscription::count());
        $this->assertSame(1, Subscription::where('account_id', $account->id)->count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, AccountMembership::count());
    }

    public function test_failed_issuance_rolls_back_before_waiting_initializer_repairs_subscription(): void
    {
        app(PlanCatalogSeeder::class)->run();
        $account = Account::withoutEvents(fn () => Account::factory()->create(['settings' => ['platform' => ['billing_contact' => ['name' => 'Prior contact']]]]));
        $activation = $this->activation($account, 'prior@example.test');
        $before = $this->baseline();
        $settings = $account->fresh()->settings;
        $failed = $this->worker('issue', ['account' => $account->id, 'email' => 'replacement@example.test', 'rollback_provisioning' => true]);
        $this->waitFor($failed, 'held');
        $initializer = $this->worker('initialize');
        $this->assertWaiting($initializer, 'accounts');
        $this->release($failed);
        $result = $this->workerResult($failed);
        $this->assertSame(500, $result['status']);
        $this->assertTrue($result['injected']);
        $this->assertSame(200, $this->workerResult($initializer)['status']);
        $this->assertSame(1, Subscription::where('account_id', $account->id)->count());
        $this->assertSame($settings, $account->fresh()->settings);
        $this->assertTrue($before['activations'] === OwnerActivation::orderBy('id')->get()->map->getAttributes()->all(), 'Prior activation changed.');
        $this->assertNull($activation->fresh()->revoked_at);
        $this->assertTrue($before['users'] === User::orderBy('id')->get()->map->getAttributes()->all(), 'Existing identities changed.');
        $this->assertEquals($before['memberships'], DB::table('account_memberships')->orderBy('id')->get()->all());
        $this->assertSame($before['plans'], Plan::orderBy('id')->get()->map->getAttributes()->all());
    }

    private function activation(Account $account, string $email): OwnerActivation
    {
        return OwnerActivation::create(['account_id' => $account->id, 'email' => $email, 'owner_name' => 'Prior owner', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDays(7)]);
    }

    private function baseline(): array
    {
        return [
            'plans' => Plan::orderBy('id')->get()->map->getAttributes()->all(),
            'users' => User::orderBy('id')->get()->map->getAttributes()->all(),
            'memberships' => DB::table('account_memberships')->orderBy('id')->get()->all(),
            'activations' => OwnerActivation::orderBy('id')->get()->map->getAttributes()->all(),
            'departments' => Department::withoutGlobalScopes()->count(),
        ];
    }
}
