<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\ErpTrialLifecycle;
use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpQueries;
use App\Erp\Commercial\OfficeErpRecurringBilling;
use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpCommercialGate;
use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpContractController;
use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Tests\OfficeErpCommercialTestCase;

class TrialLifecycleTest extends OfficeErpCommercialTestCase
{
    public function test_expiry_command_rejects_batches_outside_the_bounded_range(): void
    {
        $this->artisan('office-erp:expire-trials', ['--batch' => 0])->assertExitCode(Command::INVALID);
        $this->artisan('office-erp:expire-trials', ['--batch' => 101])->assertExitCode(Command::INVALID);
    }

    public function test_expired_trial_denies_operating_writes_at_inclusive_end(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $company = $this->trialCompany($now->subDays(7), $now);

        self::assertSame('erp_trial_expired', app(ErpCommercialGate::class)->operatingWriteDenialCode($company));
        self::assertFalse(app(ErpCommercialGate::class)->allowsOperatingWrites($company));
    }

    public function test_trial_end_is_compared_as_an_inclusive_instant_across_timezones(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $company = $this->trialCompany(
            CarbonImmutable::parse('2026-10-18T09:00:00-03:00')->utc(),
            CarbonImmutable::parse('2026-10-25T09:00:00-03:00')->utc(),
        );

        self::assertSame('erp_trial_expired', app(ErpCommercialGate::class)->operatingWriteDenialCode($company));
        self::assertSame('2026-10-25T12:00:00+00:00', $company->trial->ends_at->utc()->toIso8601String());
    }

    public function test_unexpired_trial_with_accepted_frozen_contract_grants_modules(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $company = $this->trialCompany($now->subDays(6), $now->addHours(24));
        $gate = app(ErpCommercialGate::class);

        self::assertNull($gate->operatingWriteDenialCode($company));
        self::assertTrue($gate->allowsOperatingWrites($company));
        self::assertTrue($gate->moduleEnabled($company, 'purchases'));
        self::assertFalse($gate->moduleEnabled($company, 'sales'));
    }

    public function test_missing_grant_and_manual_contract_hold_deny_trial_writes(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $company = $this->trialCompany($now->subDays(6), $now->addHours(24), withGrant: false);
        $gate = app(ErpCommercialGate::class);

        self::assertSame('erp_subscription_inactive', $gate->operatingWriteDenialCode($company));
        self::assertFalse($gate->allowsOperatingWrites($company));

        $company = $this->trialCompany($now->subDays(6), $now->addHours(24));
        OfficeErpContract::query()->where('erp_company_id', $company->id)->update(['manual_hold' => true]);

        self::assertSame('erp_subscription_inactive', $gate->operatingWriteDenialCode($company));
        self::assertFalse($gate->allowsOperatingWrites($company));

        $company = $this->trialCompany($now->subDays(6), $now->addHours(24));
        $company->subscription()->firstOrFail()->forceFill(['status' => 'active'])->save();
        $company->unsetRelation('subscription');
        self::assertSame('erp_subscription_inactive', $gate->operatingWriteDenialCode($company));
    }

    public function test_trial_expiry_marks_only_unconverted_trial_and_releases_its_allocation(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $company = $this->trialCompany($now->subDays(7), $now);
        $trial = $company->trial;
        $contractId = (int) $company->subscription->office_erp_contract_id;
        OfficeErpContract::query()->whereKey($contractId)->update(['manual_hold' => true]);
        $allocation = OfficeErpLicenceAllocation::query()->where('erp_company_id', $company->id)->firstOrFail();

        app(ErpTrialLifecycle::class)->expire($trial, $now);

        self::assertSame('trial_expired', $company->subscription()->firstOrFail()->fresh()->status);
        self::assertSame($now->toISOString(), $trial->fresh()->expired_at->toISOString());
        self::assertSame('released', $allocation->fresh()->state);
        self::assertNull($trial->fresh()->converted_at);
        self::assertTrue((bool) OfficeErpContract::query()->whereKey($contractId)->value('manual_hold'));
    }

    public function test_exact_trial_end_blocks_http_write_before_scheduler_and_keeps_owner_read(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        config(['erp.operating_writes_enabled' => true]);
        $company = $this->trialCompany($now->subDays(7), $now);
        $trial = $company->trial;
        $subscription = $company->subscription;
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id)
            ->postJson('/api/v1/erp/establishments', ['name' => 'Bloqueada'])
            ->assertForbidden()
            ->assertJsonPath('code', 'erp_trial_expired');

        $this->getJson('/api/v1/erp/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial.situation', 'expired');
        self::assertSame($trial->started_at->toISOString(), $trial->fresh()->started_at->toISOString());
        self::assertSame($subscription->id, $company->subscription()->firstOrFail()->id);
    }

    public function test_trial_does_not_generate_recurring_invoice(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $company = $this->trialCompany($now->subDays(6), $now->addHours(24));
        $contract = OfficeErpContract::query()->where('erp_company_id', $company->id)->firstOrFail();

        $result = app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, $now);

        self::assertSame(['generated' => [], 'pending' => false], $result);
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        self::assertNull($contract->fresh()->service_anchor_on);
    }

    public function test_office_contract_reader_exposes_trial_summary_and_paid_next_billing_date(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $company = $this->trialCompany($now->subDays(6), $now->addHours(24));
        $trial = $company->trial;
        $contract = OfficeErpContract::query()->where('erp_company_id', $company->id)->firstOrFail();
        $account = Account::query()->findOrFail($trial->account_id);
        $ctx = $this->officeContextFor($account);
        $service = app(OfficeErpContracts::class);

        $trialData = $this->withOfficeAccount($ctx, fn (): array => $service->show($ctx, (int) $contract->id))['data'];
        self::assertSame((int) $trial->id, $trialData['trial']['id']);
        self::assertSame($trial->ends_at->toIso8601String(), $trialData['trial']['ends_at']);
        self::assertSame('trialing', $trialData['trial']['situation']);
        self::assertSame('trialing', $trialData['licence_status']);
        self::assertSame((int) $contract->erp_plan_id, $trialData['erp_plan_id']);
        self::assertSame('Básico ERP', $trialData['erp_plan']['name']);
        self::assertNull($trialData['next_billing_on']);
        self::assertContains($trialData['company']['owner_setup_status'], ['unclaimed', 'claimed']);
        $indexResponse = $this->withOfficeAccount($ctx, function () use ($ctx): JsonResponse {
            $request = request();
            $request->attributes->set(OfficeErpExecutionContext::class, $ctx);
            try {
                return app(OfficeErpContractController::class)->index(
                    $request,
                    app(OfficeErpAdmission::class),
                    app(OfficeErpContracts::class),
                    app(OfficeErpQueries::class),
                );
            } finally {
                $request->attributes->remove(OfficeErpExecutionContext::class);
            }
        });
        $indexData = $indexResponse->getData(true)['data'][0];
        self::assertSame((int) $trial->id, $indexData['trial']['id']);
        self::assertSame((int) $contract->erp_plan_id, $indexData['erp_plan']['id']);
        self::assertSame('Básico ERP', $indexData['erp_plan']['name']);
        self::assertSame($trialData['company']['owner_setup_status'], $indexData['company']['owner_setup_status']);
        self::assertNull($indexData['next_billing_on']);
        self::assertStringContainsString('no-store', strtolower((string) $indexResponse->headers->get('Cache-Control')));

        $trial->forceFill(['converted_at' => $now])->save();
        $company->subscription()->firstOrFail()->forceFill(['status' => 'active'])->save();
        $contract->forceFill([
            'agreement_status' => 'effective',
            'service_anchor_on' => '2026-10-01',
            'next_period_index' => 1,
            'next_period_month_offset' => 1,
        ])->save();
        $paidData = $this->withOfficeAccount($ctx, fn (): array => $service->show($ctx, (int) $contract->id))['data'];
        self::assertSame('converted', $paidData['trial']['situation']);
        self::assertSame('2026-11-11', $paidData['next_billing_on']);

        $contract->forceFill(['cancel_effective_on' => '2026-11-01'])->save();
        $cancelingData = $this->withOfficeAccount($ctx, fn (): array => $service->show($ctx, (int) $contract->id))['data'];
        self::assertNull($cancelingData['next_billing_on']);
    }

    private function trialCompany(CarbonImmutable $startedAt, CarbonImmutable $endsAt, bool $withGrant = true): ErpCompany
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx, ['module_keys' => ['purchases']]);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $company = ErpCompany::factory()->create();
        $contract->forceFill([
            'erp_company_id' => $company->id,
            'agreement_status' => 'accepted',
            'provisioning_source' => 'office_trial',
            'erp_plan_id' => $plan->id,
        ])->save();
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'office_erp_contract_id' => $contract->id,
            'status' => 'trialing',
        ]);
        if ($withGrant) {
            ErpSubscriptionTrial::query()->create([
                'erp_subscription_id' => $subscription->id,
                'erp_company_id' => $company->id,
                'account_id' => $ctx->accountId,
                'cnpj' => $company->cnpj,
                'started_at' => $startedAt,
                'ends_at' => $endsAt,
                'link_revision' => 1,
            ]);
        }
        OfficeErpLicenceAllocation::query()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
        ]);

        return $company->fresh(['subscription', 'trial']);
    }
}
