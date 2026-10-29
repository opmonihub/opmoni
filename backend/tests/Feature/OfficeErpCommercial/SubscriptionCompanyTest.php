<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpCompanyRegistrations;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\ErpCommercialGate;
use App\Http\Middleware\ResolveOfficeErpCommercial;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\OfficeErpCommercialTestCase;

class SubscriptionCompanyTest extends OfficeErpCommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1/erp-commercial')->middleware([
            StartSession::class,
            ResolveOfficeErpCommercial::class,
        ])
            ->group(base_path('routes/office-erp-subscriptions.php'));
    }

    public function test_assignment_reserves_one_licence_without_activation_or_invoice_and_replays_safely(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyRegistrations::class)
            ->register($ctx, ['client_ids' => [(int) $client->id]], 'subscription-company-register'));
        $companyId = (int) DB::table('office_erp_company_registrations')->where('client_id', $client->id)->value('erp_company_id');
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);
        $payload = [
            'erp_plan_id' => (int) $plan->id,
            'price' => '125.50',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'start_on' => '2026-11-01',
            'billing_timezone' => 'America/Sao_Paulo',
        ];

        $first = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'company-assignment-one')
            ->postJson("/api/v1/erp-commercial/companies/{$companyId}/assignments", $payload)
            ->assertCreated();
        $again = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'company-assignment-one')
            ->postJson("/api/v1/erp-commercial/companies/{$companyId}/assignments", $payload)
            ->assertCreated();

        self::assertSame($first->json('data'), $again->json('data'));
        self::assertNull($first->json('data.invoice_id'));
        self::assertSame('accepted', $first->json('data.agreement_status'));
        self::assertSame('pending', $first->json('data.licence_status'));
        $this->assertDatabaseCount('office_erp_contracts', 1);
        $this->assertDatabaseCount('office_erp_licence_allocations', 1);
        $this->assertDatabaseCount('office_erp_invoices', 0);
        $this->assertDatabaseHas('office_erp_licence_allocations', ['contract_id' => $first->json('data.contract_id'), 'state' => 'occupied']);
        $this->assertDatabaseHas('erp_subscriptions', [
            'erp_company_id' => $companyId,
            'office_erp_contract_id' => $first->json('data.contract_id'),
            'status' => 'pending',
        ]);
    }

    public function test_assignment_activation_uses_the_planned_start_as_the_invoice_anchor(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00 America/Sao_Paulo');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyRegistrations::class)
            ->register($ctx, ['client_ids' => [(int) $client->id]], 'subscription-company-register-activation'));
        $companyId = (int) DB::table('office_erp_company_registrations')->where('client_id', $client->id)->value('erp_company_id');
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);
        $assignment = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'company-assignment-activation')
            ->postJson("/api/v1/erp-commercial/companies/{$companyId}/assignments", [
                'erp_plan_id' => (int) $plan->id,
                'price' => '125.50',
                'currency' => 'BRL',
                'interval_months' => 1,
                'due_in_days' => 10,
                'start_on' => '2026-11-01',
                'billing_timezone' => 'America/Sao_Paulo',
            ])->assertCreated();
        $contractId = $assignment->json('data.contract_id');

        $activation = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'company-assignment-activate')
            ->postJson("/api/v1/erp-commercial/contracts/{$contractId}/activate", [])->assertOk();
        $replay = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'company-assignment-activate')
            ->postJson("/api/v1/erp-commercial/contracts/{$contractId}/activate", [])->assertOk();
        self::assertSame($activation->json('data'), $replay->json('data'));

        $contract = OfficeErpContract::query()->findOrFail($contractId);
        self::assertSame('effective', $contract->agreement_status);
        self::assertSame('2026-11-01', $contract->service_anchor_on->toDateString());
        $this->assertDatabaseHas('office_erp_invoices', ['contract_id' => $contractId, 'period_start' => '2026-11-01']);
        $this->assertDatabaseCount('office_erp_invoices', 1);
        $company = ErpCompany::query()->findOrFail($companyId);
        self::assertFalse(app(ErpCommercialGate::class)->moduleEnabled($company, 'purchases'));
        CarbonImmutable::setTestNow('2026-11-01 00:00:00 America/Sao_Paulo');
        self::assertTrue(app(ErpCommercialGate::class)->moduleEnabled($company->fresh(), 'purchases'));
        CarbonImmutable::setTestNow();
    }

    public function test_subscription_company_reads_resolve_the_linked_contract_and_paginate_company_history(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        [$companyId, $contractId] = $this->registeredAssignment($ctx, 'subscription-company-read');
        $clientId = (int) DB::table('office_erp_company_registrations')->where('erp_company_id', $companyId)->value('client_id');
        $newerHistoryId = (int) OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $clientId,
            'erp_company_id' => $companyId,
            'agreement_status' => 'canceled',
        ])->id;

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/subscription-companies?sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $companyId)
            ->assertJsonPath('data.0.relationship', 'current')
            ->assertJsonPath('data.0.current_contract_id', $contractId)
            ->assertJsonPath('data.0.current_contract.id', $contractId);
        $this->asOffice($ctx)->getJson("/api/v1/erp-commercial/companies/{$companyId}/subscription")
            ->assertOk()
            ->assertJsonPath('data.company.id', $companyId)
            ->assertJsonPath('data.subscription.status', 'pending')
            ->assertJsonPath('data.current_contract.id', $contractId)
            ->assertJsonPath('data.current_contract.invoice_ids', []);
        $this->asOffice($ctx)->getJson("/api/v1/erp-commercial/companies/{$companyId}/contracts?per_page=25")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => $contractId])
            ->assertJsonFragment(['id' => $newerHistoryId]);
    }

    public function test_unassigned_technical_subscription_is_not_exposed_as_an_applied_subscription(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyRegistrations::class)
            ->register($ctx, ['client_ids' => [(int) $client->id]], 'subscription-company-unassigned-register'));
        $companyId = (int) DB::table('office_erp_company_registrations')->where('client_id', $client->id)->value('erp_company_id');

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/subscription-companies')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->asOffice($ctx)->getJson("/api/v1/erp-commercial/companies/{$companyId}/subscription")
            ->assertOk()->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.current_contract', null)
            ->assertJsonPath('data.allocation', null);
    }

    public function test_canceling_a_pending_assignment_releases_capacity_and_allows_a_new_eligible_assignment(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$companyId, $firstContractId] = $this->registeredAssignment($ctx, 'subscription-company-cancel-first');

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'subscription-company-cancel-pending')
            ->postJson("/api/v1/erp-commercial/contracts/{$firstContractId}/cancel", [
                'mode' => 'immediate',
                'reason' => 'Cancelamento confirmado da atribuição pendente',
            ])->assertOk();

        $this->assertDatabaseHas('office_erp_contracts', ['id' => $firstContractId, 'agreement_status' => 'canceled']);
        $this->assertDatabaseHas('office_erp_licence_allocations', ['contract_id' => $firstContractId, 'state' => 'released']);
        $this->assertDatabaseHas('erp_subscriptions', ['erp_company_id' => $companyId, 'status' => 'canceled']);
        $this->assertDatabaseCount('office_erp_invoices', 0);

        $planId = (int) OfficeErpContract::query()->whereKey($firstContractId)->value('erp_plan_id');
        $second = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'subscription-company-cancel-reassign')
            ->postJson("/api/v1/erp-commercial/companies/{$companyId}/assignments", [
                'erp_plan_id' => $planId,
                'price' => '125.50',
                'currency' => 'BRL',
                'interval_months' => 1,
                'due_in_days' => 10,
                'start_on' => '2026-11-01',
                'billing_timezone' => 'America/Sao_Paulo',
            ])->assertCreated();

        self::assertNotSame($firstContractId, $second->json('data.contract_id'));
        $this->assertDatabaseHas('erp_subscriptions', [
            'erp_company_id' => $companyId,
            'office_erp_contract_id' => $second->json('data.contract_id'),
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('office_erp_licence_allocations', 2);
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    public function test_registered_company_with_owner_cannot_be_adopted_by_assignment(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyRegistrations::class)
            ->register($ctx, ['client_ids' => [(int) $client->id]], 'subscription-company-owned-register'));
        $companyId = (int) DB::table('office_erp_company_registrations')->where('client_id', $client->id)->value('erp_company_id');
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);
        $owner = User::factory()->create();
        ErpCompanyMembership::query()->create(['erp_company_id' => $companyId, 'user_id' => $owner->id, 'role' => 'owner']);
        ErpCompany::query()->whereKey($companyId)->update(['owner_setup_status' => 'claimed']);

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'subscription-company-owned-assignment')
            ->postJson("/api/v1/erp-commercial/companies/{$companyId}/assignments", [
                'erp_plan_id' => (int) $plan->id, 'price' => '125.50', 'currency' => 'BRL',
                'interval_months' => 1, 'due_in_days' => 10, 'start_on' => '2026-11-01', 'billing_timezone' => 'America/Sao_Paulo',
            ])->assertStatus(409);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_licence_allocations', 0);
    }

    public function test_company_invoice_projection_is_paginated_and_scoped_to_company_history(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00 America/Sao_Paulo');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$companyId, $contractId] = $this->registeredAssignment($ctx, 'subscription-company-invoice-read');
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'subscription-company-invoice-activate')
            ->postJson("/api/v1/erp-commercial/contracts/{$contractId}/activate", [])->assertOk();

        $this->asOffice($ctx)->getJson("/api/v1/erp-commercial/companies/{$companyId}/invoices?per_page=25")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.contract_id', $contractId)
            ->assertJsonPath('data.0.period_start', '2026-11-01')
            ->assertJsonPath('data.0.original_total', '125.50')
            ->assertJsonPath('data.0.balance.outstanding', '125.50');
    }

    public function test_subscription_company_list_uses_bounded_queries_for_one_hundred_companies(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 200, 'module_keys' => ['purchases']]);
        $clients = collect(range(1, 100))->map(fn (): Client => $this->clientFor($ctx));
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyRegistrations::class)
            ->register($ctx, ['client_ids' => $clients->map(fn ($client): int => (int) $client->id)->all()], 'subscription-company-list-100-register'));

        $this->withOfficeAccount($ctx, function () use ($ctx, $clients): void {
            foreach ($clients as $client) {
                $companyId = (int) DB::table('office_erp_company_registrations')->where('client_id', $client->id)->value('erp_company_id');
                $contract = OfficeErpContract::factory()->create([
                    'account_id' => $ctx->accountId,
                    'client_id' => $client->id,
                    'erp_company_id' => $companyId,
                    'agreement_status' => 'accepted',
                    'provisioning_source' => 'office_admin',
                ]);
                DB::table('erp_subscriptions')->where('erp_company_id', $companyId)->update([
                    'office_erp_contract_id' => $contract->id,
                    'status' => 'pending',
                ]);
            }
        });

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $response = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/subscription-companies?per_page=100')->assertOk();

        self::assertSame(100, $response->json('meta.total'));
        self::assertLessThan(50, $queries, 'A 100-company list must not issue per-row authorization or contract queries.');
    }

    public function test_status_sort_places_missing_subscription_status_last_in_both_directions(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        [$currentCompanyId] = $this->registeredAssignment($ctx, 'subscription-company-status-order');
        $client = $this->clientFor($ctx);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyRegistrations::class)
            ->register($ctx, ['client_ids' => [(int) $client->id]], 'subscription-company-status-order-history-register'));
        $historicalCompanyId = (int) DB::table('office_erp_company_registrations')->where('client_id', $client->id)->value('erp_company_id');
        $this->withOfficeAccount($ctx, fn () => OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'erp_company_id' => $historicalCompanyId,
            'agreement_status' => 'canceled',
        ]));

        foreach (['asc', 'desc'] as $direction) {
            $ids = $this->asOffice($ctx)->getJson("/api/v1/erp-commercial/subscription-companies?sort=status&direction={$direction}&per_page=25")
                ->assertOk()->json('data.*.id');
            self::assertSame([$currentCompanyId, $historicalCompanyId], $ids);
        }
    }

    /** @return array{0:int,1:int} */
    private function registeredAssignment(OfficeErpExecutionContext $ctx, string $key): array
    {
        $client = $this->clientFor($ctx);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyRegistrations::class)
            ->register($ctx, ['client_ids' => [(int) $client->id]], $key.'-register'));
        $companyId = (int) DB::table('office_erp_company_registrations')->where('client_id', $client->id)->value('erp_company_id');
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);
        $assigned = $this->asOffice($ctx)->withHeader('Idempotency-Key', $key.'-assign')
            ->postJson("/api/v1/erp-commercial/companies/{$companyId}/assignments", [
                'erp_plan_id' => (int) $plan->id,
                'price' => '125.50',
                'currency' => 'BRL',
                'interval_months' => 1,
                'due_in_days' => 10,
                'start_on' => '2026-11-01',
                'billing_timezone' => 'America/Sao_Paulo',
            ])->assertCreated();

        return [$companyId, (int) $assigned->json('data.contract_id')];
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);

        return $this->actingAs($ctx->actor)->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
