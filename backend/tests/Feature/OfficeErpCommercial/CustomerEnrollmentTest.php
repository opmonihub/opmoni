<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\OfficeErpCommercialTestCase;

class CustomerEnrollmentTest extends OfficeErpCommercialTestCase
{
    public function test_existing_client_enrolls_without_offer_or_owner_acceptance(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 15:00:00 UTC');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);

        $response = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-existing-client')
            ->postJson('/api/v1/erp-commercial/enrollments', $this->payload($client->id, $plan->id));

        self::assertSame(201, $response->status(), json_encode($response->json()));
        $response->assertJsonPath('data.owner_setup_status', 'unclaimed');
        $companyId = $response->json('data.company_id');
        $this->assertDatabaseCount('erp_subscriptions', 1);
        $this->assertDatabaseCount('office_erp_invoices', 1);
        $this->assertDatabaseMissing('erp_company_memberships', ['erp_company_id' => $companyId, 'user_id' => $ctx->actor->id]);
        $this->assertDatabaseHas('office_erp_licence_allocations', ['erp_company_id' => $companyId, 'state' => 'occupied']);
    }

    public function test_new_client_lookup_failure_leaves_no_partial_records(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $plan = $this->technicalPlan();
        Http::fake(['publica.cnpj.ws/*' => Http::response([], 503)]);
        $before = [
            'clients' => DB::table('clients')->count(),
            'companies' => ErpCompany::query()->count(),
            'subscriptions' => ErpSubscription::query()->count(),
            'contracts' => OfficeErpContract::query()->count(),
            'allocations' => OfficeErpLicenceAllocation::query()->count(),
            'invoices' => OfficeErpInvoice::query()->count(),
        ];

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-new-client-provider-failure')
            ->postJson('/api/v1/erp-commercial/enrollments', $this->newClientPayload($plan->id))
            ->assertStatus(503);

        self::assertSame($before, [
            'clients' => DB::table('clients')->count(),
            'companies' => ErpCompany::query()->count(),
            'subscriptions' => ErpSubscription::query()->count(),
            'contracts' => OfficeErpContract::query()->count(),
            'allocations' => OfficeErpLicenceAllocation::query()->count(),
            'invoices' => OfficeErpInvoice::query()->count(),
        ]);
    }

    public function test_direct_enrollment_replay_has_one_invoice(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $plan = $this->technicalPlan();
        $payload = $this->payload($client->id, $plan->id);
        $first = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-replay')
            ->postJson('/api/v1/erp-commercial/enrollments', $payload)->assertCreated();
        $retry = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-replay')
            ->postJson('/api/v1/erp-commercial/enrollments', $payload)->assertCreated();
        $this->assertSame($first->json('data'), $retry->json('data'));
        $this->assertDatabaseCount('office_erp_invoices', 1);
        $this->assertDatabaseCount('office_erp_contracts', 1);
        $this->assertDatabaseCount('office_erp_licence_allocations', 1);
        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/operations/enroll-replay', ['Idempotency-Key' => 'enroll-replay'])
            ->assertOk()->assertExactJson(['data' => $first->json('data')]);
    }

    public function test_foreign_or_inactive_client_is_not_enrolled(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $foreign = $this->clientFor($this->officeContext());
        $inactive = $this->clientFor($ctx);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $plan = $this->technicalPlan();
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-foreign-client')
            ->postJson('/api/v1/erp-commercial/enrollments', $this->payload($foreign->id, $plan->id))->assertNotFound();
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-inactive-client')
            ->postJson('/api/v1/erp-commercial/enrollments', $this->payload($inactive->id, $plan->id))->assertNotFound();
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    public function test_soft_deleted_active_client_is_not_enrolled(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $client->delete();
        $plan = $this->technicalPlan();

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-archived-client')
            ->postJson('/api/v1/erp-commercial/enrollments', $this->payload($client->id, $plan->id))
            ->assertNotFound();

        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    public function test_new_client_enrollment_replay_skips_second_provider_lookup_and_preflight_has_no_transaction(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $plan = $this->technicalPlan();
        $transactionLevels = [];
        $baselineTransactionLevel = DB::transactionLevel();
        Http::fake(function () use (&$transactionLevels) {
            $transactionLevels[] = DB::transactionLevel();

            return Http::response([], 404);
        });

        $payload = $this->newClientPayload($plan->id);
        $first = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-new-client-replay')
            ->postJson('/api/v1/erp-commercial/enrollments', $payload)->assertCreated();
        $retry = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-new-client-replay')
            ->postJson('/api/v1/erp-commercial/enrollments', $payload)->assertCreated();

        $this->assertSame($first->json('data'), $retry->json('data'));
        $this->assertSame([$baselineTransactionLevel], $transactionLevels);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('office_erp_invoices', 1);
    }

    public function test_membership_revoked_during_provider_preflight_is_rechecked_before_effects(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $plan = $this->technicalPlan();
        $transactionLevels = [];
        $baselineTransactionLevel = DB::transactionLevel();
        Http::fake(function () use ($ctx, &$transactionLevels) {
            $transactionLevels[] = DB::transactionLevel();
            DB::table('account_memberships')->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->delete();

            return Http::response([], 404);
        });
        $before = [DB::table('clients')->count(), ErpCompany::query()->count(), ErpSubscription::query()->count()];

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-revoked-during-provider')
            ->postJson('/api/v1/erp-commercial/enrollments', $this->newClientPayload($plan->id))
            ->assertForbidden();

        $this->assertSame([$baselineTransactionLevel], $transactionLevels);
        $this->assertSame($before, [DB::table('clients')->count(), ErpCompany::query()->count(), ErpSubscription::query()->count()]);
    }

    public function test_changed_payload_same_key_conflicts_without_second_enrollment(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $plan = $this->technicalPlan();
        $payload = $this->payload($client->id, $plan->id);
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-payload-conflict')
            ->postJson('/api/v1/erp-commercial/enrollments', $payload)->assertCreated();
        $payload['price'] = '150.00';

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-payload-conflict')
            ->postJson('/api/v1/erp-commercial/enrollments', $payload)->assertStatus(409);

        $this->assertDatabaseCount('office_erp_contracts', 1);
        $this->assertDatabaseCount('office_erp_invoices', 1);
    }

    public function test_provider_failure_after_client_persistence_rolls_back_enrollment_aggregate(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $plan = $this->technicalPlan();
        $before = [DB::table('clients')->count(), ErpCompany::query()->count(), ErpSubscription::query()->count()];
        Http::fake(['publica.cnpj.ws/*' => Http::response([], 404)]);
        \Event::listen('eloquent.creating: '.OfficeErpInvoice::class, static function (): never {
            throw new \RuntimeException('invoice producer failed');
        });

        $this->withoutExceptionHandling();
        try {
            $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-rolls-back-invoice-failure')
                ->postJson('/api/v1/erp-commercial/enrollments', $this->newClientPayload($plan->id));
            self::fail('The invoice failure should abort the enrollment transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('invoice producer failed', $exception->getMessage());
        } finally {
            \Event::forget('eloquent.creating: '.OfficeErpInvoice::class);
            $this->withExceptionHandling();
        }

        $this->assertSame($before, [DB::table('clients')->count(), ErpCompany::query()->count(), ErpSubscription::query()->count()]);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    public function test_invalid_source_plan_terms_and_future_start_have_no_effect(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 15:00:00 UTC');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $plan = $this->technicalPlan();
        $future = $this->payload($client->id, $plan->id);
        $future['start_on'] = '2026-10-26';
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-future-start')
            ->postJson('/api/v1/erp-commercial/enrollments', $future)->assertUnprocessable()->assertJsonValidationErrors('start_on');
        $invalidPrice = $this->payload($client->id, $plan->id);
        $invalidPrice['price'] = '01.999';
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-invalid-price')
            ->postJson('/api/v1/erp-commercial/enrollments', $invalidPrice)->assertUnprocessable();
        $invalidCycle = $this->payload($client->id, $plan->id);
        $invalidCycle['interval_months'] = 2;
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-invalid-cycle')
            ->postJson('/api/v1/erp-commercial/enrollments', $invalidCycle)->assertUnprocessable();
        $missingPlan = $this->payload($client->id, $plan->id);
        $missingPlan['erp_plan_id'] = 999999;
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-missing-plan')
            ->postJson('/api/v1/erp-commercial/enrollments', $missingPlan)->assertNotFound();
        $foreignModulePlan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $foreignModulePlan->id, 'module_key' => 'sales']);
        $foreignModule = $this->payload($client->id, $foreignModulePlan->id);
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-forbidden-module')
            ->postJson('/api/v1/erp-commercial/enrollments', $foreignModule)->assertForbidden();
        $bothSources = $this->payload($client->id, $plan->id);
        $bothSources['new_client'] = ['tax_id' => '27865757000102', 'tax_regime' => 'presumed_profit', 'name' => 'Empresa'];
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-conflicting-sources')
            ->postJson('/api/v1/erp-commercial/enrollments', $bothSources)->assertUnprocessable();
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    public function test_owned_company_requires_explicit_current_owner_association(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $company = ErpCompany::factory()->create(['cnpj' => $client->tax_id]);
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $plan = $this->technicalPlan();

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'enroll-owned-company')
            ->postJson('/api/v1/erp-commercial/enrollments', $this->payload($client->id, $plan->id))
            ->assertStatus(409);

        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    private function technicalPlan(): ErpPlan
    {
        $plan = ErpPlan::factory()->create();
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);

        return $plan;
    }

    /** @return array<string,mixed> */
    private function newClientPayload(int $planId): array
    {
        return [
            'new_client' => ['tax_id' => '27865757000102', 'tax_regime' => 'presumed_profit', 'name' => 'Empresa de teste'],
            'erp_plan_id' => $planId,
            'price' => '149.90',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'start_on' => '2026-10-25',
            'billing_timezone' => 'America/Sao_Paulo',
        ];
    }

    /** @return array<string, mixed> */
    private function payload(int $clientId, int $planId): array
    {
        return [
            'client_id' => $clientId,
            'erp_plan_id' => $planId,
            'price' => '149.90',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'start_on' => '2026-10-25',
            'billing_timezone' => 'America/Sao_Paulo',
        ];
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
