<?php

namespace Tests\Feature;

use App\Actions\AccessAccounts;
use App\Actions\EnsureAccountSubscription;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Department;
use App\Models\OwnerActivation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PlatformProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public static function catalogSeeders(): array
    {
        return [
            'catalog' => [PlanCatalogSeeder::class],
            'legacy entry point' => [PlanSeeder::class],
        ];
    }

    #[DataProvider('catalogSeeders')]
    public function test_repeat_seeding_preserves_all_edited_catalog_columns(string $seeder): void
    {
        $this->seed($seeder);
        $basico = Plan::bySlug('basico');
        $basico->update(['name' => 'Negotiated plan', 'limits' => ['users' => 12, 'clients' => 150]]);
        $professional = Plan::bySlug('profissional');
        $professional->update(['name' => 'Legacy professional', 'limits' => ['users' => null, 'custom_limit' => 7]]);
        Plan::create(['slug' => 'custom', 'name' => 'Custom', 'limits' => ['users' => 2]]);
        $before = Plan::orderBy('id')->get()->map->getAttributes()->all();

        $this->seed($seeder);
        $this->seed($seeder);

        $this->assertSame($before, Plan::orderBy('id')->get()->map->getAttributes()->all());
    }

    #[DataProvider('catalogSeeders')]
    public function test_partial_catalog_recovery_only_creates_missing_defaults(string $seeder): void
    {
        $existing = Plan::create(['slug' => 'basico', 'name' => 'Edited basic', 'limits' => ['users' => 9]]);
        $before = $existing->fresh()->getAttributes();

        $this->seed($seeder);
        $this->seed($seeder);

        $this->assertDatabaseCount('plans', 3);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame('Profissional', Plan::bySlug('profissional')->name);
        $this->assertSame(['users' => 20, 'clients' => 500, 'monitorings' => null], Plan::bySlug('profissional')->limits);
        $this->assertSame('Empresarial', Plan::bySlug('empresarial')->name);
        $this->assertSame(['users' => null, 'clients' => null, 'monitorings' => null], Plan::bySlug('empresarial')->limits);
    }

    public function test_repeated_command_backfills_across_chunks_without_resetting_existing_data(): void
    {
        $this->seed(PlanSeeder::class);
        $missing = Account::withoutEvents(fn () => Account::factory()->count(203)->create());
        $canceled = Account::factory()->create();
        $pastDue = Account::factory()->create();
        $canceled->subscription->update(['plan_id' => Plan::bySlug('profissional')->id, 'status' => 'canceled']);
        $pastDue->subscription->update(['plan_id' => Plan::bySlug('empresarial')->id, 'status' => 'past_due']);
        $subscriptionsBefore = Subscription::orderBy('id')->get()->map->getAttributes()->all();
        AccountMembership::factory()->create(['account_id' => $canceled->id]);
        OwnerActivation::create([
            'account_id' => $missing->first()->id,
            'email' => 'pending@example.com',
            'owner_name' => 'Pending owner',
            'token_hash' => hash('sha256', 'pending-owner-token'),
            'expires_at' => now()->addDays(7),
        ]);
        $membershipsBefore = DB::table('account_memberships')->get()->all();
        $activationsBefore = DB::table('owner_activations')->get()->all();
        $usersBefore = User::count();
        $departmentsBefore = Department::withoutGlobalScopes()->count();

        $this->artisan('platform:initialize')->expectsOutput('Subscriptions created: 203')->assertSuccessful();
        $afterFirstRun = Subscription::orderBy('id')->get()->map->getAttributes()->all();
        $this->artisan('platform:initialize')->expectsOutput('Subscriptions created: 0')->assertSuccessful();

        $this->assertSame($afterFirstRun, Subscription::orderBy('id')->get()->map->getAttributes()->all());
        foreach ($subscriptionsBefore as $subscription) {
            $this->assertSame($subscription, Subscription::findOrFail($subscription['id'])->getAttributes());
        }
        $this->assertSame(203, Subscription::whereIn('account_id', $missing->modelKeys())->where('status', 'active')->where('plan_id', Plan::bySlug('basico')->id)->count());
        $this->assertDatabaseCount('subscriptions', 205);
        $this->assertDatabaseCount('accounts', 205);
        $this->assertDatabaseCount('users', $usersBefore);
        $this->assertEquals($membershipsBefore, DB::table('account_memberships')->get()->all());
        $this->assertEquals($activationsBefore, DB::table('owner_activations')->get()->all());
        $this->assertSame($departmentsBefore, Department::withoutGlobalScopes()->count());
    }

    public function test_command_initializes_empty_catalog_without_creating_users_or_accounts(): void
    {
        $this->artisan('platform:initialize')->expectsOutput('Subscriptions created: 0')->assertSuccessful();
        $this->artisan('platform:initialize')->expectsOutput('Subscriptions created: 0')->assertSuccessful();

        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseEmpty('accounts');
        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('subscriptions');
    }

    public function test_fresh_bootstrap_atomically_initializes_catalog_subscription_and_departments(): void
    {
        [$user, $account] = app(AccessAccounts::class)->bootstrap($this->bootstrapData());

        $this->assertProvisioned($account);
        $this->assertDatabaseHas('account_memberships', ['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->assertTrue($user->is_super_admin);
        $this->assertDatabaseEmpty('owner_activations');
    }

    public function test_fresh_issuance_atomically_initializes_catalog_subscription_and_departments(): void
    {
        [$account, $activation] = app(AccessAccounts::class)->issue($this->issuanceData());

        $this->assertProvisioned($account);
        $this->assertDatabaseHas('owner_activations', ['account_id' => $account->id, 'email' => 'owner@example.com']);
        $this->assertNotEmpty($activation['url']);
        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('account_memberships');
    }

    public function test_issuance_repairs_missing_subscription_for_an_existing_account(): void
    {
        $account = Account::withoutEvents(fn () => Account::factory()->create());

        app(AccessAccounts::class)->issue($this->issuanceData(), $account->id);

        $this->assertSame('basico', $account->subscription->plan->slug);
        $this->assertSame('active', $account->subscription->status);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('owner_activations', 1);
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_existing_subscription_is_returned_without_catalog_initialization_or_changes(): void
    {
        $plan = Plan::create(['slug' => 'custom', 'name' => 'Custom', 'limits' => []]);
        $account = Account::withoutEvents(fn () => Account::factory()->create());
        $subscription = $account->subscription()->create(['plan_id' => $plan->id, 'status' => 'canceled']);
        $before = $subscription->fresh()->getAttributes();

        $ensured = app(EnsureAccountSubscription::class)->handle($account);

        $this->assertSame($before, $ensured->getAttributes());
        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public static function accountCreationFlows(): array
    {
        return ['bootstrap' => ['bootstrap'], 'issuance' => ['issue']];
    }

    #[DataProvider('accountCreationFlows')]
    public function test_provisioning_failure_rolls_back_the_entire_account_creation_flow(string $flow): void
    {
        $this->injectProvisioningFailure();
        $failure = null;

        try {
            app(AccessAccounts::class)->{$flow}($flow === 'bootstrap' ? $this->bootstrapData() : $this->issuanceData());
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure, 'Provisioning failure must abort account creation.');
        $this->assertSame('Injected provisioning failure', $failure->getMessage());
        foreach (['accounts', 'users', 'account_memberships', 'subscriptions', 'owner_activations', 'departments', 'plans'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_failed_existing_account_provisioning_preserves_prior_activation(): void
    {
        $account = Account::withoutEvents(fn () => Account::factory()->create());
        $activation = OwnerActivation::create([
            'account_id' => $account->id,
            'email' => 'owner@example.com',
            'owner_name' => 'Pending owner',
            'token_hash' => hash('sha256', 'prior-owner-token'),
            'expires_at' => now()->addDays(7),
        ]);
        $before = $activation->fresh()->getAttributes();
        $this->injectProvisioningFailure();
        $failure = null;

        try {
            app(AccessAccounts::class)->issue($this->issuanceData(), $account->id);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure, 'Provisioning failure must abort issuance.');
        $this->assertSame('Injected provisioning failure', $failure->getMessage());
        $this->assertDatabaseCount('accounts', 1);
        $this->assertSame($before, $activation->fresh()->getAttributes());
        $this->assertDatabaseCount('owner_activations', 1);
        $this->assertDatabaseEmpty('subscriptions');
        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('account_memberships');
        $this->assertDatabaseEmpty('plans');
    }

    private function injectProvisioningFailure(): void
    {
        $this->app->bind(EnsureAccountSubscription::class, fn () => new class extends EnsureAccountSubscription
        {
            public function handle(Account $account): Subscription
            {
                parent::handle($account);

                throw new RuntimeException('Injected provisioning failure');
            }
        });
    }

    private function assertProvisioned(Account $account): void
    {
        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame('active', $account->subscription->status);
        $this->assertSame('basico', $account->subscription->plan->slug);
        $this->assertSame('Básico', $account->subscription->plan->name);
        $this->assertSame(['users' => 5, 'clients' => 50, 'monitorings' => null], $account->subscription->plan->limits);
        $this->assertSame(['Contábil', 'Fiscal', 'Pessoal', 'Societário'], Department::withoutGlobalScopes()->where('account_id', $account->id)->orderBy('name')->pluck('name')->all());
    }

    private function bootstrapData(): array
    {
        return ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'company' => 'Office', 'size' => 'solo'];
    }

    private function issuanceData(): array
    {
        return ['name' => 'Office', 'owner_name' => 'Owner', 'login_email' => 'owner@example.com'];
    }
}
