<?php

namespace Tests;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Plan;
use App\Models\User;
use App\Support\CurrentAccount;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class OfficeErpCommercialTestCase extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->assertIsolatedTestStores();

        parent::setUp();
    }

    private function assertIsolatedTestStores(): void
    {
        $testing = getenv('APP_ENV') === 'testing';
        $databaseUrl = getenv('DB_URL');
        $sqliteMemory = $testing
            && getenv('DB_CONNECTION') === 'sqlite'
            && getenv('DB_DATABASE') === ':memory:'
            && ($databaseUrl === false || $databaseUrl === '');
        $isolatedPostgres = $testing
            && getenv('DB_CONNECTION') === 'pgsql'
            && getenv('DB_DATABASE') === 'opmoni_test'
            && getenv('DB_HOST') === 'postgres'
            && getenv('DB_USERNAME') === 'opmoni'
            && ($databaseUrl === false || $databaseUrl === '')
            && getenv('CACHE_STORE') === 'redis'
            && getenv('SESSION_DRIVER') === 'redis'
            && getenv('REDIS_HOST') === 'redis'
            && getenv('REDIS_DB') === '13'
            && getenv('REDIS_CACHE_DB') === '14'
            && getenv('REDIS_SESSION_DB') === '15'
            && in_array(getenv('REDIS_PREFIX'), ['opmoni-access-test-', 'opmoni-office-erp-commercial-'], true);

        if (! $sqliteMemory && ! $isolatedPostgres) {
            throw new \RuntimeException('Office ERP commercial tests require SQLite memory or the isolated opmoni_test and Redis 13-15 stores before RefreshDatabase.');
        }
    }

    /** @param array<string, mixed> $allowance */
    protected function officeContext(array $allowance = []): OfficeErpExecutionContext
    {
        $account = Account::factory()->create(['status' => 'active']);
        if ($allowance !== []) {
            $plan = Plan::factory()->create(['erp_commercial' => $allowance]);
            $subscription = $account->subscription()->firstOrFail();
            $subscription->forceFill(['plan_id' => $plan->id, 'status' => 'active'])->save();
        }

        return $this->officeContextFor($account);
    }

    protected function officeContextFor(Account $account, ?string $purpose = null): OfficeErpExecutionContext
    {
        $actor = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $account->id,
            'user_id' => $actor->id,
            'role' => 'admin',
        ]);

        return $this->withSelectedOffice(
            $account,
            AccountMembership::query()
                ->where('account_id', $account->id)
                ->where('user_id', $actor->id)
                ->where('role', 'admin')
                ->firstOrFail(),
            fn (): OfficeErpExecutionContext => OfficeErpExecutionContext::officeAdmin(
                $account,
                $actor,
                $purpose ?? 'commercial-test:'.$account->id.':'.$actor->id,
            ),
        );
    }

    protected function systemContext(Account $account, string $purpose): OfficeErpExecutionContext
    {
        return OfficeErpExecutionContext::system($account, $purpose);
    }

    protected function clientFor(OfficeErpExecutionContext $ctx): Client
    {
        return $this->withOfficeAccount($ctx, fn (): Client => Client::factory()->company()->create([
            'account_id' => $ctx->accountId,
        ]));
    }

    protected function withOfficeAccount(OfficeErpExecutionContext $ctx, Closure $callback): mixed
    {
        $ctx->assertVerified();
        abort_unless($ctx->actorKind === 'office_admin' && $ctx->actor instanceof User, 403);
        $membership = AccountMembership::query()
            ->where('account_id', $ctx->accountId)
            ->where('user_id', $ctx->actor->getKey())
            ->where('role', 'admin')
            ->firstOrFail();

        return $this->withSelectedOffice($ctx->account, $membership, $callback);
    }

    protected function withSelectedOffice(Account $account, AccountMembership $membership, Closure $callback): mixed
    {
        $request = request();
        $hadPrevious = $request->attributes->has(CurrentAccount::class);
        $previous = $request->attributes->get(CurrentAccount::class);
        $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));

        try {
            return $callback();
        } finally {
            if ($hadPrevious) {
                $request->attributes->set(CurrentAccount::class, $previous);
            } else {
                $request->attributes->remove(CurrentAccount::class);
            }
        }
    }

    /** @param array<string, mixed> $terms */
    protected function acceptedContract(OfficeErpExecutionContext $ctx, array $terms = []): OfficeErpContract
    {
        $plan = OfficeErpSalesPlan::factory()->create(['account_id' => $ctx->accountId]);
        $planRevision = OfficeErpSalesPlanRevision::factory()->create([
            'account_id' => $ctx->accountId,
            'sales_plan_id' => $plan->id,
            'price' => $terms['price'] ?? '100.00',
            'interval_months' => $terms['interval_months'] ?? 1,
            'due_in_days' => $terms['due_in_days'] ?? 10,
            'module_keys' => $terms['module_keys'] ?? ['purchases'],
        ]);
        $plan->forceFill(['current_revision_id' => $planRevision->id])->save();

        $client = $this->clientFor($ctx);
        $contract = OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'sales_plan_id' => $plan->id,
            'agreement_status' => 'accepted',
            'billing_timezone' => 'America/Sao_Paulo',
        ]);
        $revision = OfficeErpContractRevision::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'price' => $terms['price'] ?? '100.00',
            'currency' => 'BRL',
            'interval_months' => $terms['interval_months'] ?? 1,
            'due_in_days' => $terms['due_in_days'] ?? 10,
            'module_keys' => $terms['module_keys'] ?? ['purchases'],
        ]);
        $contract->forceFill(['current_revision_id' => $revision->id])->save();

        return $contract->refresh();
    }
}
