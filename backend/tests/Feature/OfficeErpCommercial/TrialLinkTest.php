<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\OfficeErpCommercialTestCase;

final class TrialLinkTest extends OfficeErpCommercialTestCase
{
    public function test_selected_office_admin_can_configure_rotate_and_read_public_safe_availability(): void
    {
        config(['access.origin' => 'https://public.example.test']);
        config(['erp.office_trial_enabled' => true]);
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 3, 'module_keys' => ['purchases']]);
        $technicalPlan = $this->technicalPlan();
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $this->getJson('/api/v1/erp-commercial/trial-link')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.plans.0.id', $technicalPlan->id)
            ->assertJsonPath('data.plans.0.module_keys', ['purchases']);
        $configured = $this->withHeaders(['Host' => 'backend.internal', 'Origin' => 'https://public.example.test'])
            ->putJson('/api/v1/erp-commercial/trial-link', [
                'erp_plan_id' => $technicalPlan->id,
                'enabled' => true,
            ], ['Idempotency-Key' => '11111111-1111-4111-8111-111111111111'])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.plan.name', 'ERP Básico Teste');

        $link = OfficeErpTrialLink::query()->where('account_id', $ctx->accountId)->firstOrFail();
        $code = (string) $link->public_code;
        $public = $this->getJson('/api/v1/erp/trials/'.$code)
            ->assertOk()
            ->assertJsonPath('data.duration_days', 7)
            ->assertJsonPath('data.available', true)
            ->assertJsonMissingPath('data.clients')
            ->assertJsonMissingPath('data.account_id');

        self::assertSame((string) $ctx->account->name, $public->json('data.sponsor.name'));
        self::assertStringContainsString('no-store', (string) $public->headers->get('Cache-Control'));
        self::assertSame(
            'https://public.example.test/erp/testar/'.$code,
            $configured->json('data.url'),
        );
        self::assertSame(
            'https://public.example.test/erp/testar/'.$code,
            $this->withHeaders(['Host' => 'backend.internal', 'Origin' => 'https://public.example.test'])
                ->getJson('/api/v1/erp-commercial/trial-link')->json('data.url'),
        );

        $rotatedResponse = $this->postJson('/api/v1/erp-commercial/trial-link/rotate', [], [
            'Idempotency-Key' => '22222222-2222-4222-8222-222222222222',
        ])->assertOk()->assertJsonPath('data.revision', 2);

        $rotated = $link->fresh();
        self::assertNotSame($code, $rotated->public_code);
        self::assertSame(2, (int) $rotated->revision);
        $this->getJson('/api/v1/erp/trials/'.$code)->assertNotFound();
        $this->getJson('/api/v1/erp/trials/'.$rotated->public_code)->assertOk()->assertJsonPath('data.available', true);

        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => true,
        ], ['Idempotency-Key' => '11111111-1111-4111-8111-111111111111'])
            ->assertOk()
            ->assertJsonPath('data.url', $configured->json('data.url'));
        $this->postJson('/api/v1/erp-commercial/trial-link/rotate', [], [
            'Idempotency-Key' => '22222222-2222-4222-8222-222222222222',
        ])->assertOk()->assertJsonPath('data.url', $rotatedResponse->json('data.url'));
        self::assertSame(2, (int) $link->fresh()->revision);

        Subscription::query()->where('account_id', $ctx->accountId)->update(['status' => 'canceled']);
        $this->getJson('/api/v1/erp-commercial/operations/11111111-1111-4111-8111-111111111111')->assertForbidden();
        $this->getJson('/api/v1/erp-commercial/operations/22222222-2222-4222-8222-222222222222')->assertForbidden();
    }

    public function test_link_configuration_is_scoped_to_selected_real_office_admin(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $technicalPlan = $this->technicalPlan();
        $other = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => true,
        ], ['Idempotency-Key' => '33333333-3333-4333-8333-333333333333'])->assertOk();

        $this->withHeader('X-Account-Id', (string) $other->accountId)
            ->getJson('/api/v1/erp-commercial/trial-link')
            ->assertConflict()
            ->assertJsonPath('code', 'account_context_changed');

        $operator = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $ctx->accountId,
            'user_id' => $operator->id,
            'role' => 'operador',
        ]);
        $this->selectBusinessAccount($operator, $ctx->accountId);
        $this->actingAs($operator);
        $this->withHeader('X-Account-Id', (string) $ctx->accountId)
            ->getJson('/api/v1/erp-commercial/trial-link')
            ->assertForbidden();
    }

    public function test_release_flag_wrong_near_prefix_and_zero_capacity_are_safe(): void
    {
        config(['erp.office_trial_enabled' => false]);
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 0, 'module_keys' => ['purchases']]);
        $technicalPlan = $this->technicalPlan();
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => true,
        ], ['Idempotency-Key' => '44444444-4444-4444-8444-444444444444'])->assertOk();

        $code = (string) OfficeErpTrialLink::query()->where('account_id', $ctx->accountId)->value('public_code');
        $this->getJson('/api/v1/erp/trials/'.$code)->assertNotFound();
        config(['erp.office_trial_enabled' => true]);
        $unknownCode = substr($code, 0, -1).((substr($code, -1) === '0') ? '1' : '0');
        self::assertSame(36, strlen($unknownCode));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $unknownCode);
        $this->getJson('/api/v1/erp/trials/'.$unknownCode)->assertNotFound();
        $this->getJson('/api/v1/erp/trials/'.$code)->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'licence_capacity_reached');

        config(['erp.office_trial_enabled' => false]);
        $this->getJson('/api/v1/erp/trials/'.$code)->assertNotFound();
    }

    public function test_disabled_link_is_unavailable_without_public_sponsor_data(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $technicalPlan = $this->technicalPlan();
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => true,
        ], ['Idempotency-Key' => '55555555-5555-4555-8555-555555555555'])->assertOk();

        $code = (string) OfficeErpTrialLink::query()->where('account_id', $ctx->accountId)->value('public_code');
        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => false,
        ], ['Idempotency-Key' => '66666666-6666-4666-8666-666666666666'])->assertOk();

        $this->getJson('/api/v1/erp/trials/'.$code)->assertNotFound()->assertJsonMissingPath('data.sponsor');
    }

    public function test_public_availability_releases_expired_trial_capacity_before_scheduled_reconciliation(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $code = $this->publicLinkFor($ctx);
        Carbon::setTestNow('2026-10-25 12:00:00 UTC');
        $this->createTrialAllocation($ctx, 'trialing', now(), null);

        try {
            $this->getJson('/api/v1/erp/trials/'.$code)
                ->assertOk()
                ->assertJsonPath('data.available', true);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_public_availability_counts_future_trials_and_converted_trial_seats(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $futureTrialOffice = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $futureTrialCode = $this->publicLinkFor($futureTrialOffice);
        $this->createTrialAllocation($futureTrialOffice, 'trialing', now()->addDay(), null);
        $this->getJson('/api/v1/erp/trials/'.$futureTrialCode)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'licence_capacity_reached');

        $convertedOffice = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $convertedCode = $this->publicLinkFor($convertedOffice);
        $this->createTrialAllocation($convertedOffice, 'active', now()->subDay(), now()->subHour());
        $this->getJson('/api/v1/erp/trials/'.$convertedCode)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'licence_capacity_reached');
    }

    private function technicalPlan(): ErpPlan
    {
        $plan = ErpPlan::factory()->create(['name' => 'ERP Básico Teste']);
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);

        return $plan->load('modules');
    }

    private function publicLinkFor(OfficeErpExecutionContext $ctx): string
    {
        $plan = $this->technicalPlan();
        $link = OfficeErpTrialLink::query()->create([
            'account_id' => $ctx->accountId,
            'public_code' => (string) Str::uuid(),
            'erp_plan_id' => $plan->id,
            'enabled' => true,
            'revision' => 1,
            'created_by_user_id' => $ctx->actor?->getKey(),
            'updated_by_user_id' => $ctx->actor?->getKey(),
        ]);

        return (string) $link->public_code;
    }

    private function createTrialAllocation(
        OfficeErpExecutionContext $ctx,
        string $subscriptionStatus,
        Carbon $endsAt,
        ?Carbon $convertedAt,
    ): void {
        $company = ErpCompany::factory()->create();
        $client = $this->clientFor($ctx);
        $contract = OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'agreement_status' => 'effective',
            'erp_company_id' => $company->id,
        ]);
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'status' => $subscriptionStatus,
            'office_erp_contract_id' => $contract->id,
        ]);
        ErpSubscriptionTrial::query()->create([
            'erp_subscription_id' => $subscription->id,
            'erp_company_id' => $company->id,
            'account_id' => $ctx->accountId,
            'cnpj' => $company->cnpj,
            'started_at' => $endsAt->copy()->subDays(7),
            'ends_at' => $endsAt,
            'converted_at' => $convertedAt,
            'link_revision' => 1,
        ]);
        OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
            'expires_at' => null,
        ]);
    }
}
