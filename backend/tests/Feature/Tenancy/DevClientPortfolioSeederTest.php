<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\SerproClientAuthorization;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DevClientPortfolioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevClientPortfolioSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeder(int $account, int $count = 200): void
    {
        $this->app->call([new DevClientPortfolioSeeder, 'run'], ['account' => $account, 'count' => $count]);
    }

    public function test_seeds_two_hundred_clients_with_all_deadline_states(): void
    {
        $account = Account::factory()->create();
        $accountsBefore = Account::count();
        $usersBefore = User::count();

        $this->runSeeder($account->getKey());

        $clients = Client::withoutGlobalScopes()->where('account_id', $account->getKey());

        $this->assertSame(200, (clone $clients)->count());
        $this->assertSame(200, (clone $clients)->distinct()->count('tax_id'));
        $this->assertSame($accountsBefore, Account::count());
        $this->assertSame($usersBefore, User::count());

        foreach (['missing', 'valid', 'expiring', 'expired'] as $state) {
            $this->assertGreaterThan(
                0,
                (clone $clients)->withDeadlineStatus($state)->count(),
                "expected clients with deadline {$state}"
            );
        }

        $this->assertSame(
            140,
            ClientCertificate::withoutGlobalScopes()
                ->where('account_id', $account->getKey())
                ->whereNull('replaced_at')
                ->whereNull('removed_at')
                ->count()
        );
        $this->assertGreaterThan(
            0,
            SerproClientAuthorization::withoutGlobalScopes()->where('account_id', $account->getKey())->count()
        );

        $marker = Tag::query()->where('account_id', $account->getKey())->where('name', 'seed-dev')->firstOrFail();
        $this->assertSame(200, $marker->clients()->count());
    }

    public function test_second_run_replaces_previous_seed_and_keeps_other_tenants(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        Client::factory()->count(3)->create(['account_id' => $other->getKey()]);

        $this->runSeeder($account->getKey());
        $first = Client::withoutGlobalScopes()->where('account_id', $account->getKey())->orderBy('id')->pluck('id')->all();

        $this->runSeeder($account->getKey());
        $second = Client::withoutGlobalScopes()->where('account_id', $account->getKey())->orderBy('id')->pluck('id')->all();

        $this->assertCount(200, $second);
        $this->assertSame([], array_intersect($first, $second));
        $this->assertSame(200, Client::withoutGlobalScopes()->where('account_id', $account->getKey())->distinct()->count('tax_id'));
        $this->assertSame(3, Client::withoutGlobalScopes()->where('account_id', $other->getKey())->count());
    }

    public function test_seeder_ensures_unlimited_plan_subscription(): void
    {
        $account = Account::factory()->create();
        $account->subscription()->delete();

        $this->assertDatabaseMissing('subscriptions', ['account_id' => $account->getKey()]);

        $this->runSeeder($account->getKey());

        $subscription = $account->subscription()->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('empresarial', $subscription->plan->slug);
    }

    public function test_custom_count_scales_the_portfolio(): void
    {
        $account = Account::factory()->create();

        $this->runSeeder($account->getKey(), 20);

        $clients = Client::withoutGlobalScopes()->where('account_id', $account->getKey());

        $this->assertSame(20, (clone $clients)->count());
        $this->assertSame(20, (clone $clients)->distinct()->count('tax_id'));
    }
}
