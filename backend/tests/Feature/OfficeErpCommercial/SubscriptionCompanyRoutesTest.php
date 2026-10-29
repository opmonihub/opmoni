<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMoney;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\OfficeErpCommercialTestCase;

final class SubscriptionCompanyRoutesTest extends OfficeErpCommercialTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_registered_company_assignment_activation_and_module_reduction_use_registered_routes(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00 America/Sao_Paulo');

        self::assertTrue(Route::has('office-erp-commercial.companies.store'));
        self::assertTrue(Route::has('office-erp-commercial.assignments.store'));
        self::assertTrue(Route::has('office-erp-commercial.contracts.activate'));
        self::assertTrue(Route::has('office-erp-commercial.contracts.amend'));
        self::assertTrue(Route::has('office-erp-commercial.operations.lookup'));

        $ctx = $this->officeContext([
            'enabled' => true,
            'licence_limit' => 2,
            'module_keys' => ['purchases', 'stock_transfers'],
        ]);
        $client = $this->clientFor($ctx);
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'stock_transfers']);

        $registrationKey = 'route-company-register-001';
        $registration = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/companies', [
            'client_ids' => [(int) $client->id],
        ], ['Idempotency-Key' => $registrationKey])->assertCreated();
        $companyId = (int) $registration->json('data.created.0.company_id');
        self::assertGreaterThan(0, $companyId);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_licence_allocations', 0);
        $this->assertDatabaseCount('office_erp_invoices', 0);

        $registrationReceipt = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/operations/'.$registrationKey)
            ->assertOk()
            ->assertJsonPath('data.created.0.company_id', $companyId);
        self::assertArrayNotHasKey('payload', $registrationReceipt->json('data'));

        $assignmentKey = 'route-company-assign-001';
        $terms = [
            'erp_plan_id' => (int) $plan->id,
            'price' => '125.50',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'start_on' => '2026-11-01',
            'billing_timezone' => 'America/Sao_Paulo',
        ];
        $assignment = $this->asOffice($ctx)->postJson(
            "/api/v1/erp-commercial/companies/{$companyId}/assignments",
            $terms,
            ['Idempotency-Key' => $assignmentKey],
        )->assertCreated()
            ->assertJsonPath('data.company_id', $companyId)
            ->assertJsonPath('data.agreement_status', 'accepted')
            ->assertJsonPath('data.licence_status', 'pending')
            ->assertJsonPath('data.invoice_id', null);
        $contractId = (int) $assignment->json('data.contract_id');
        self::assertSame(1, DB::table('office_erp_licence_allocations')->where('contract_id', $contractId)->where('state', 'occupied')->count());
        $contract = OfficeErpContract::query()->findOrFail($contractId);
        self::assertSame('2026-11-01', $contract->planned_start_on->toDateString());
        self::assertSame('125.50', OfficeErpMoney::canonical((string) $contract->currentRevision->getRawOriginal('price'), 'price'));
        self::assertSame('125.50', $terms['price']);
        self::assertSame('BRL', $contract->currentRevision->currency);
        self::assertSame(1, (int) $contract->currentRevision->interval_months);
        self::assertSame(10, (int) $contract->currentRevision->due_in_days);
        self::assertSame(['purchases', 'stock_transfers'], $contract->currentRevision->module_keys);
        $this->assertDatabaseCount('office_erp_invoices', 0);

        $assignmentReceipt = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/operations/'.$assignmentKey)
            ->assertOk()
            ->assertJsonPath('data.company_id', $companyId)
            ->assertJsonPath('data.contract_id', $contractId)
            ->assertJsonPath('data.invoice_id', null);
        self::assertArrayNotHasKey('payload', $assignmentReceipt->json('data'));

        $replayedAssignment = $this->asOffice($ctx)->postJson(
            "/api/v1/erp-commercial/companies/{$companyId}/assignments",
            $terms,
            ['Idempotency-Key' => $assignmentKey],
        )->assertCreated();
        self::assertSame($assignment->json('data'), $replayedAssignment->json('data'));
        $this->assertDatabaseCount('office_erp_contracts', 1);
        $this->assertDatabaseCount('office_erp_licence_allocations', 1);

        $activationKey = 'route-contract-activate-001';
        $activation = $this->asOffice($ctx)->postJson(
            "/api/v1/erp-commercial/contracts/{$contractId}/activate",
            [],
            ['Idempotency-Key' => $activationKey],
        )->assertOk();
        $invoiceId = (int) $activation->json('data.invoice_id');
        self::assertGreaterThan(0, $invoiceId);
        $this->assertDatabaseCount('office_erp_invoices', 1);

        $activationReceipt = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/operations/'.$activationKey)
            ->assertOk()
            ->assertJsonPath('data.contract_id', $contractId)
            ->assertJsonPath('data.invoice_id', $invoiceId)
            ->assertJsonPath('data.subscription_id', $assignment->json('data.subscription_id'));
        self::assertArrayNotHasKey('payload', $activationReceipt->json('data'));

        $replayedActivation = $this->asOffice($ctx)->postJson(
            "/api/v1/erp-commercial/contracts/{$contractId}/activate",
            [],
            ['Idempotency-Key' => $activationKey],
        )->assertOk();
        self::assertSame($activation->json('data'), $replayedActivation->json('data'));
        $this->assertDatabaseCount('office_erp_invoices', 1);
        self::assertSame('2026-11-01', OfficeErpContract::query()->findOrFail($contractId)->service_anchor_on->toDateString());

        $amendmentKey = 'route-contract-reduce-001';
        $amendment = $this->asOffice($ctx)->patchJson(
            "/api/v1/erp-commercial/contracts/{$contractId}/terms",
            ['module_keys' => ['purchases']],
            ['Idempotency-Key' => $amendmentKey],
        )->assertOk();
        $revisionId = (int) $amendment->json('data.revision_id');
        self::assertGreaterThan(0, $revisionId);
        $this->assertDatabaseHas('office_erp_commercial_operations', [
            'idempotency_key' => $amendmentKey,
            'action' => 'contract.amend.modules.reduce',
        ]);
        $amendmentReceipt = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/operations/'.$amendmentKey)
            ->assertOk()
            ->assertJsonPath('data.revision_id', $revisionId);
        self::assertArrayNotHasKey('payload', $amendmentReceipt->json('data'));
        self::assertSame(['purchases', 'stock_transfers'], OfficeErpContract::query()->findOrFail($contractId)->currentRevision->module_keys);
        self::assertSame(['purchases'], OfficeErpContract::query()->findOrFail($contractId)->pendingRevision->module_keys);
    }

    public function test_registered_subscription_routes_hide_foreign_companies_and_reject_stale_account_assertions(): void
    {
        $office = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $foreignOffice = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $foreignClient = $this->clientFor($foreignOffice);

        $foreignRegistration = $this->asOffice($foreignOffice)->postJson('/api/v1/erp-commercial/companies', [
            'client_ids' => [(int) $foreignClient->id],
        ], ['Idempotency-Key' => 'route-foreign-register-001'])->assertCreated();
        $foreignCompanyId = (int) $foreignRegistration->json('data.created.0.company_id');
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);

        $this->asOffice($office)->postJson(
            "/api/v1/erp-commercial/companies/{$foreignCompanyId}/assignments",
            [
                'erp_plan_id' => (int) $plan->id,
                'price' => '10.00',
                'currency' => 'BRL',
                'interval_months' => 1,
                'due_in_days' => 10,
                'start_on' => '2026-11-01',
                'billing_timezone' => 'America/Sao_Paulo',
            ],
            ['Idempotency-Key' => 'route-foreign-assignment-001'],
        )->assertNotFound();

        $this->asOffice($office)->withHeader('X-Account-Id', (string) $foreignOffice->accountId)
            ->getJson('/api/v1/erp-commercial/subscription-companies')
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_context_changed');
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_licence_allocations', 0);
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);

        return $this->actingAs($ctx->actor)->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
