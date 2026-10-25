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
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class OfficeErpCommercialTestCase extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Office ERP commercial tests require SQLite memory before RefreshDatabase.');
        }

        parent::setUp();
    }

    /** @param array<string, mixed> $allowance */
    protected function officeContext(array $allowance = []): OfficeErpExecutionContext
    {
        $account = Account::factory()->create(['status' => 'active']);
        if ($allowance !== []) {
            $plan = Plan::factory()->create(['erp_commercial' => $allowance]);
            Subscription::factory()->create(['account_id' => $account->id, 'plan_id' => $plan->id, 'status' => 'active']);
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

        return OfficeErpExecutionContext::officeAdmin(
            $account,
            $actor,
            $purpose ?? 'commercial-test:'.$account->id.':'.$actor->id,
        );
    }

    protected function systemContext(Account $account, string $purpose): OfficeErpExecutionContext
    {
        return OfficeErpExecutionContext::system($account, $purpose);
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
            'module_keys' => $terms['module_keys'] ?? ['erp.sales'],
        ]);
        $plan->forceFill(['current_revision_id' => $planRevision->id])->save();

        $client = Client::factory()->company()->create(['account_id' => $ctx->accountId]);
        $contract = OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'agreement_status' => 'accepted',
            'billing_timezone' => 'America/Sao_Paulo',
        ]);
        $revision = OfficeErpContractRevision::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'sales_plan_revision_id' => $planRevision->id,
            'price' => $terms['price'] ?? '100.00',
            'currency' => 'BRL',
            'interval_months' => $terms['interval_months'] ?? 1,
            'due_in_days' => $terms['due_in_days'] ?? 10,
            'module_keys' => $terms['module_keys'] ?? ['erp.sales'],
        ]);
        $contract->forceFill(['current_revision_id' => $revision->id])->save();

        return $contract->refresh();
    }
}
