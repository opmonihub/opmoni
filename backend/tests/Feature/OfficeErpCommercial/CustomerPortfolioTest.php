<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\OfficeErpCommercialTestCase;

class CustomerPortfolioTest extends OfficeErpCommercialTestCase
{
    public function test_client_rows_include_latest_linked_subscription_summaries_without_foreign_clients_or_n_plus_one_queries(): void
    {
        CarbonImmutable::setTestNow('2026-10-25T15:00:00Z');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 10, 'module_keys' => ['purchases']]);

        $plainClient = $this->namedClient($ctx, 'Portfolio 00 No ERP');
        $paidClient = $this->namedClient($ctx, 'Portfolio 01 Paid');
        $olderContract = OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $paidClient->id,
            'agreement_status' => 'draft',
        ]);
        $paidContract = $this->contractFor($ctx, $paidClient, 'effective');
        $paidContract->forceFill([
            'service_anchor_on' => '2026-10-01',
            'next_period_index' => 1,
            'next_period_month_offset' => 1,
        ])->save();
        $paidPlan = ErpPlan::factory()->create(['name' => 'Paid plan']);
        $paidCompany = ErpCompany::factory()->create();
        $this->subscriptionFor($ctx, $paidContract, $paidCompany, $paidPlan, 'active');

        $trialClient = $this->namedClient($ctx, 'Portfolio 02 Trial');
        $trialContract = $this->contractFor($ctx, $trialClient, 'accepted', 'office_trial');
        $trialPlan = ErpPlan::factory()->create(['name' => 'Trial plan']);
        $trialCompany = ErpCompany::factory()->create();
        $trialSubscription = $this->subscriptionFor($ctx, $trialContract, $trialCompany, $trialPlan, 'trialing');
        ErpSubscriptionTrial::query()->create([
            'erp_subscription_id' => $trialSubscription->id,
            'erp_company_id' => $trialCompany->id,
            'account_id' => $ctx->accountId,
            'cnpj' => $trialCompany->cnpj,
            'started_at' => '2026-10-20T15:00:00Z',
            'ends_at' => '2026-10-27T15:00:00Z',
            'link_revision' => 1,
        ]);

        $expiredClient = $this->namedClient($ctx, 'Portfolio 03 Expired');
        $expiredContract = $this->contractFor($ctx, $expiredClient, 'accepted', 'office_trial');
        $expiredPlan = ErpPlan::factory()->create(['name' => 'Expired plan']);
        $expiredCompany = ErpCompany::factory()->create();
        $expiredSubscription = $this->subscriptionFor($ctx, $expiredContract, $expiredCompany, $expiredPlan, 'trial_expired');
        ErpSubscriptionTrial::query()->create([
            'erp_subscription_id' => $expiredSubscription->id,
            'erp_company_id' => $expiredCompany->id,
            'account_id' => $ctx->accountId,
            'cnpj' => $expiredCompany->cnpj,
            'started_at' => '2026-10-10T15:00:00Z',
            'ends_at' => '2026-10-17T15:00:00Z',
            'expired_at' => '2026-10-17T15:00:00Z',
            'link_revision' => 1,
        ]);

        $canceledClient = $this->namedClient($ctx, 'Portfolio 04 Canceled');
        $canceledContract = $this->contractFor($ctx, $canceledClient, 'canceled');
        $canceledPlan = ErpPlan::factory()->create(['name' => 'Canceled plan']);
        $canceledCompany = ErpCompany::factory()->create();
        $this->subscriptionFor($ctx, $canceledContract, $canceledCompany, $canceledPlan, 'canceled');

        $foreignClient = $this->namedClient($this->officeContext(), 'Portfolio 00 Foreign');
        for ($index = 5; $index < 26; $index++) {
            $this->namedClient($ctx, sprintf('Portfolio %02d Filler', $index));
        }
        self::assertGreaterThan($olderContract->id, $paidContract->id);

        $tables = ['erp_companies', 'erp_subscriptions', 'erp_subscription_trials', 'erp_plans', 'office_erp_contract_revisions', 'office_erp_licence_allocations'];
        $queryCounts = array_fill_keys($tables, 0);
        DB::listen(static function (QueryExecuted $query) use (&$queryCounts, $tables): void {
            $sql = strtolower($query->sql);
            foreach ($tables as $table) {
                $isTableQuery = preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?\b/', $sql) === 1;
                $isEagerAllocationQuery = $table !== 'office_erp_licence_allocations'
                    || preg_match('/\bwhere\b.*\bcontract_id\b.*\bin\s*\(/', $sql) === 1;
                if ($isTableQuery && $isEagerAllocationQuery) {
                    $queryCounts[$table]++;
                }
            }
        });

        $response = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/clients?per_page=25&page=1')->assertOk();
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame(26, $response->json('meta.total'));
        $this->assertCount(25, $response->json('data'));
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($rows->has($plainClient->id));
        $this->assertTrue($rows->has($paidClient->id));
        $this->assertTrue($rows->has($trialClient->id));
        $this->assertTrue($rows->has($expiredClient->id));
        $this->assertTrue($rows->has($canceledClient->id));
        $this->assertFalse($rows->has($foreignClient->id));
        $this->assertNull($rows[$plainClient->id]['subscription']);

        $paid = $rows[$paidClient->id]['subscription'];
        $this->assertSame((int) $paidContract->id, $paid['contract_id']);
        $this->assertSame('effective', $paid['agreement_status']);
        $this->assertSame('active', $paid['licence_status']);
        $this->assertSame((int) $paidPlan->id, $paid['erp_plan_id']);
        $this->assertSame('Paid plan', $paid['erp_plan']['name']);
        $this->assertNull($paid['trial']);
        $this->assertSame('2026-11-11', $paid['next_billing_on']);

        $trial = $rows[$trialClient->id]['subscription'];
        $this->assertSame('trialing', $trial['licence_status']);
        $this->assertSame('Trial plan', $trial['erp_plan']['name']);
        $this->assertSame('trialing', $trial['trial']['situation']);
        $this->assertSame('2026-10-27T15:00:00+00:00', $trial['trial']['ends_at']);
        $this->assertNull($trial['next_billing_on']);

        $expired = $rows[$expiredClient->id]['subscription'];
        $this->assertSame('trial_expired', $expired['licence_status']);
        $this->assertSame('expired', $expired['trial']['situation']);
        $this->assertSame('2026-10-17T15:00:00+00:00', $expired['trial']['ends_at']);

        $canceled = $rows[$canceledClient->id]['subscription'];
        $this->assertSame('canceled', $canceled['agreement_status']);
        $this->assertSame('canceled', $canceled['licence_status']);
        $this->assertNull($canceled['next_billing_on']);
        $this->assertDatabaseCount('office_erp_invoices', 0);

        $secondPage = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/clients?per_page=25&page=2')->assertOk();
        $this->assertSame(2, $secondPage->json('meta.current_page'));
        $this->assertCount(1, $secondPage->json('data'));
        $this->assertNull($secondPage->json('data.0.subscription'));
        foreach ($tables as $table) {
            $maxQueries = $table === 'office_erp_contract_revisions' ? 2 : 1;
            $this->assertLessThanOrEqual($maxQueries, $queryCounts[$table], "Expected bounded eager queries for {$table} on the populated page.");
        }
    }

    private function namedClient(OfficeErpExecutionContext $ctx, string $name): Client
    {
        $client = $this->clientFor($ctx);
        $client->forceFill(['name' => $name])->save();

        return $client;
    }

    private function contractFor(OfficeErpExecutionContext $ctx, Client $client, string $agreementStatus, string $source = 'office_admin'): OfficeErpContract
    {
        $contract = OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'agreement_status' => $agreementStatus,
            'provisioning_source' => $source,
        ]);
        $revision = OfficeErpContractRevision::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'price' => $source === 'office_trial' ? '0.00' : '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'module_keys' => ['purchases'],
        ]);
        $contract->forceFill(['current_revision_id' => $revision->id])->save();

        return $contract->fresh();
    }

    private function subscriptionFor(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, ErpCompany $company, ErpPlan $plan, string $status): ErpSubscription
    {
        $contract->forceFill(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id])->save();

        return ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'office_erp_contract_id' => $contract->id,
            'status' => $status,
        ]);
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
