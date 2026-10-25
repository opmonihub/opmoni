<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpCalendar;
use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class ContractLifecycleTest extends OfficeErpCommercialTestCase
{
    public function test_activation_atomically_issues_first_invoice_and_links_existing_subscription(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 13:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract, $company, $subscription] = $this->acceptedOccupiedContract($ctx);

        $result = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpContracts::class)->activate($ctx, $contract, 'activate-atomic'));

        self::assertSame((int) $contract->id, $result['contract_id']);
        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        self::assertSame(1, $company->subscription()->count());
        self::assertSame((int) $subscription->id, $result['subscription_id']);
        self::assertSame('effective', $contract->fresh()->agreement_status);
        self::assertSame('2026-10-25', $contract->fresh()->service_anchor_on->toDateString());
        self::assertSame('2026-10-25', OfficeErpInvoice::query()->firstOrFail()->period_start->toDateString());
        $retry = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpContracts::class)->activate($ctx, $contract, 'activate-atomic'));
        self::assertSame($result, $retry);
        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
    }

    public function test_invoice_failure_rolls_back_effective_status_anchor_and_subscription_link(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract, , $subscription] = $this->acceptedOccupiedContract($ctx);
        OfficeErpInvoice::creating(static function (): never {
            throw new \RuntimeException('invoice failed');
        });

        try {
            $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->activate($ctx, $contract, 'activate-failure'));
            self::fail('The activation invoice failure should propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('invoice failed', $exception->getMessage());
        }

        self::assertSame('accepted', $contract->fresh()->agreement_status);
        self::assertNull($contract->fresh()->service_anchor_on);
        self::assertNull($subscription->fresh()->office_erp_contract_id);
        self::assertSame('pending', $subscription->fresh()->status);
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
    }

    public function test_accepted_occupied_activation_after_resale_loss_does_not_allocate_another_slot(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract] = $this->acceptedOccupiedContract($ctx);
        $allocation = OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->firstOrFail();
        $occupiedBefore = OfficeErpLicenceAllocation::query()->where('account_id', $ctx->accountId)->where('state', 'occupied')->count();
        $plan = $ctx->account->subscription->plan;
        $allowance = $plan->erp_commercial;
        $allowance['enabled'] = false;
        $plan->forceFill(['erp_commercial' => $allowance])->save();

        $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->activate($ctx, $contract, 'activate-grandfathered'));

        self::assertSame('occupied', $allocation->fresh()->state);
        self::assertSame($occupiedBefore, OfficeErpLicenceAllocation::query()->where('account_id', $ctx->accountId)->where('state', 'occupied')->count());
    }

    public function test_price_only_amendment_is_maintenance_but_module_expansion_is_new_sale(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract] = $this->acceptedOccupiedContract($ctx, 'effective');
        $plan = $ctx->account->subscription->plan;
        $allowance = $plan->erp_commercial;
        $allowance['enabled'] = false;
        $plan->forceFill(['erp_commercial' => $allowance])->save();

        $this->withoutExceptionHandling();
        try {
            $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend(
                $ctx,
                $contract,
                ['module_keys' => ['purchases', 'stock_transfers']],
                'amend-modules',
            ));
            self::fail('Module expansion after resale loss must be forbidden.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $priceRevision = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend(
            $ctx,
            $contract,
            ['price' => '125.00'],
            'amend-price',
        ));
        self::assertSame('125.00', (string) $priceRevision->price);
    }

    public function test_platform_module_removal_preserves_grants_and_blocks_new_forbidden_module(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases', 'stock_transfers']]);
        [$contract, $company, $subscription] = $this->acceptedOccupiedContract($ctx, 'effective', ['module_keys' => ['purchases']]);
        $subscription->forceFill(['office_erp_contract_id' => $contract->id])->save();
        $revisionId = (int) $contract->current_revision_id;
        $revisionCount = DB::table('office_erp_contract_revisions')->where('contract_id', $contract->id)->count();
        $plan = $ctx->account->subscription->plan;
        $allowance = $plan->erp_commercial;
        $allowance['module_keys'] = ['purchases'];
        $plan->forceFill(['erp_commercial' => $allowance])->save();

        $this->withoutExceptionHandling();
        try {
            $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend(
                $ctx,
                $contract,
                ['module_keys' => ['purchases', 'stock_transfers']],
                'module-removed-from-platform-plan',
            ));
            self::fail('A module removed from the current platform allowance cannot be added to an existing contract.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        self::assertSame($revisionId, (int) $contract->fresh()->current_revision_id);
        self::assertSame($revisionCount, DB::table('office_erp_contract_revisions')->where('contract_id', $contract->id)->count());
        self::assertSame(['purchases'], $contract->fresh()->currentRevision->module_keys);
        self::assertSame('active', $subscription->fresh()->status);
        self::assertSame((int) $contract->id, (int) $subscription->fresh()->office_erp_contract_id);
        self::assertSame((int) $company->id, (int) $contract->fresh()->erp_company_id);
        self::assertSame('occupied', OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->value('state'));
    }

    public function test_suspended_account_cannot_use_real_contract_maintenance_route(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract] = $this->acceptedOccupiedContract($ctx, 'effective');
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $before = [
            'contract' => $contract->fresh()->getAttributes(),
            'revisions' => DB::table('office_erp_contract_revisions')->where('contract_id', $contract->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'operations' => DB::table('office_erp_commercial_operations')->where('account_id', $ctx->accountId)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'audits' => DB::table('office_erp_audit_events')->where('account_id', $ctx->accountId)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'invoices' => DB::table('office_erp_invoices')->where('contract_id', $contract->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
        DB::table('accounts')->where('id', $ctx->accountId)->update(['status' => 'suspended']);

        $this->patchJson('/api/v1/erp-commercial/contracts/'.$contract->id.'/terms', ['price' => '125.00'], [
            'Idempotency-Key' => 'suspended-account-contract-amendment',
        ])->assertForbidden();

        self::assertSame($before['contract'], $contract->fresh()->getAttributes());
        self::assertSame($before['revisions'], DB::table('office_erp_contract_revisions')->where('contract_id', $contract->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        self::assertSame($before['operations'], DB::table('office_erp_commercial_operations')->where('account_id', $ctx->accountId)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        self::assertSame($before['audits'], DB::table('office_erp_audit_events')->where('account_id', $ctx->accountId)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        self::assertSame($before['invoices'], DB::table('office_erp_invoices')->where('contract_id', $contract->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
    }

    public function test_period_end_cancel_keeps_invoices_and_manual_hold_cause(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract, , $subscription] = $this->acceptedOccupiedContract($ctx, 'effective');
        $invoice = $this->issuedInvoice($ctx, $contract);
        $contract->forceFill(['manual_hold' => true])->save();
        $subscription->forceFill(['office_erp_contract_id' => $contract->id, 'status' => 'suspended'])->save();
        $invoiceIdsBefore = OfficeErpInvoice::query()->where('contract_id', $contract->id)->pluck('id')->all();
        $securitySuspendedBefore = $subscription->fresh()->status;

        $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->cancel($ctx, $contract, 'period_end', 'Encerramento solicitado', 'cancel-period-end'));
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->cancel($ctx, $contract, 'period_end', 'Encerramento solicitado', 'cancel-period-end'));

        self::assertSame('effective', $contract->fresh()->agreement_status);
        self::assertSame($invoiceIdsBefore, OfficeErpInvoice::query()->where('contract_id', $contract->id)->pluck('id')->all());
        self::assertSame($securitySuspendedBefore, $subscription->fresh()->status);
        self::assertTrue($contract->fresh()->manual_hold);
    }

    public function test_monthly_to_quarterly_amendment_uses_original_anchor_at_month_end(): void
    {
        CarbonImmutable::setTestNow('2026-01-31 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract] = $this->acceptedOccupiedContract($ctx, 'effective');
        $contract->forceFill(['service_anchor_on' => '2026-01-31', 'next_period_index' => 1, 'next_period_month_offset' => 1])->save();
        $contract = $contract->fresh();
        $revision = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend($ctx, $contract, ['interval_months' => 3], 'amend-monthly-quarterly'));
        $replayed = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend($ctx, $contract, ['interval_months' => 3], 'amend-monthly-quarterly'));
        self::assertSame((int) $revision->id, (int) $replayed->id);

        CarbonImmutable::setTestNow('2026-02-28 12:00:00');
        $this->withOfficeAccount($ctx, fn () => DB::transaction(fn () => app(OfficeErpContracts::class)->applyDueChanges($ctx, $contract, CarbonImmutable::now('America/Sao_Paulo'))));

        self::assertSame((int) $revision->id, (int) $contract->fresh()->current_revision_id);
        self::assertSame(1, (int) $contract->fresh()->next_period_month_offset);
        $this->withOfficeAccount($ctx, fn () => DB::transaction(fn () => app(OfficeErpContracts::class)->applyDueChanges($ctx, $contract, CarbonImmutable::now('America/Sao_Paulo'))));
        self::assertSame(1, (int) $contract->fresh()->next_period_month_offset);
        self::assertSame(1, (int) $contract->fresh()->next_period_index);
        self::assertSame(['start' => '2026-02-28', 'end' => '2026-05-31', 'due_on' => '2026-02-28'],
            app(OfficeErpCalendar::class)->periodAtMonthOffset('2026-01-31', 3, 1, 'America/Sao_Paulo', 0));
    }

    public function test_quarterly_to_monthly_amendment_uses_cumulative_month_offset(): void
    {
        CarbonImmutable::setTestNow('2026-01-31 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract] = $this->acceptedOccupiedContract($ctx, 'effective', ['interval_months' => 3]);
        $contract->forceFill(['service_anchor_on' => '2026-01-31', 'next_period_index' => 1, 'next_period_month_offset' => 3])->save();
        $contract = $contract->fresh();
        $revision = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend($ctx, $contract, ['interval_months' => 1], 'amend-quarterly-monthly'));

        CarbonImmutable::setTestNow('2026-04-30 12:00:00');
        $this->withOfficeAccount($ctx, fn () => DB::transaction(fn () => app(OfficeErpContracts::class)->applyDueChanges($ctx, $contract, CarbonImmutable::now('America/Sao_Paulo'))));

        self::assertSame((int) $revision->id, (int) $contract->fresh()->current_revision_id);
        self::assertSame(3, (int) $contract->fresh()->next_period_month_offset);
        self::assertSame(['start' => '2026-04-30', 'end' => '2026-05-31', 'due_on' => '2026-04-30'],
            app(OfficeErpCalendar::class)->periodAtMonthOffset('2026-01-31', 1, 3, 'America/Sao_Paulo', 0));
    }

    public function test_module_expansion_outcome_remains_recoverable_after_revision_becomes_current(): void
    {
        CarbonImmutable::setTestNow('2026-01-31 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases', 'stock_transfers']]);
        [$contract] = $this->acceptedOccupiedContract($ctx, 'effective');
        $contract->forceFill(['service_anchor_on' => '2026-01-31', 'next_period_index' => 1, 'next_period_month_offset' => 1])->save();
        $contract = $contract->fresh();
        $revision = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend(
            $ctx,
            $contract,
            ['module_keys' => ['purchases', 'stock_transfers']],
            'amend-modules-recover',
        ));
        CarbonImmutable::setTestNow('2026-02-28 12:00:00');
        $this->withOfficeAccount($ctx, fn () => DB::transaction(fn () => app(OfficeErpContracts::class)->applyDueChanges($ctx, $contract, CarbonImmutable::now('America/Sao_Paulo'))));
        $plan = $ctx->account->subscription->plan;
        $allowance = $plan->erp_commercial;
        $allowance['enabled'] = false;
        $allowance['module_keys'] = [];
        $plan->forceFill(['erp_commercial' => $allowance])->save();

        $replayed = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend(
            $ctx,
            $contract,
            ['module_keys' => ['purchases', 'stock_transfers']],
            'amend-modules-recover',
        ));
        $outcome = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->lookup($ctx, 'amend-modules-recover'));

        self::assertSame((int) $revision->id, (int) $replayed->id);
        self::assertSame((int) $revision->id, (int) $outcome['data']['revision_id']);
    }

    public function test_new_accepted_contract_reuses_company_subscription_after_previous_contract_canceled(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$previous, $company, $subscription] = $this->acceptedOccupiedContract($ctx, 'effective');
        $subscription->forceFill(['office_erp_contract_id' => $previous->id])->save();
        $previousInvoiceIds = OfficeErpInvoice::query()->where('contract_id', $previous->id)->pluck('id')->all();
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->cancel($ctx, $previous, 'immediate', 'Encerramento anterior', 'cancel-before-relink'));
        self::assertSame('released', OfficeErpLicenceAllocation::query()->where('contract_id', $previous->id)->value('state'));

        $next = $this->acceptedContract($ctx);
        $next->forceFill(['erp_company_id' => $company->id])->save();
        OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $next->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
            'expires_at' => null,
        ]);

        $result = $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->activate($ctx, $next, 'activate-after-relink'));

        self::assertSame((int) $subscription->id, $result['subscription_id']);
        self::assertSame((int) $next->id, (int) $subscription->fresh()->office_erp_contract_id);
        self::assertSame('active', $subscription->fresh()->status);
        self::assertSame('canceled', $previous->fresh()->agreement_status);
        self::assertSame($previousInvoiceIds, OfficeErpInvoice::query()->where('contract_id', $previous->id)->pluck('id')->all());
        self::assertNull($this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->show($ctx, (int) $previous->id)['data']['licence_status']));
    }

    public function test_foreign_contract_routes_hide_details_and_reject_activation_without_side_effects(): void
    {
        $selectedOffice = $this->officeContext();
        $foreignOffice = $this->officeContext();
        [$foreignContract, $foreignCompany, $foreignSubscription] = $this->withOfficeAccount(
            $foreignOffice,
            fn (): array => $this->acceptedOccupiedContract($foreignOffice),
        );
        $this->withOfficeAccount($foreignOffice, function () use ($foreignContract, $foreignCompany): void {
            $foreignContract->client()->update(['name' => 'FOREIGN-CONTRACT-CLIENT-SECRET']);
            $foreignCompany->forceFill(['name' => 'FOREIGN-CONTRACT-COMPANY-SECRET'])->save();
            $foreignContract->currentRevision()->update(['price' => '9876.54']);
        });

        $this->selectBusinessAccount($selectedOffice->actor, $selectedOffice->accountId);
        $this->actingAs($selectedOffice->actor);

        $foreignClientId = (int) $foreignContract->client_id;
        $before = $this->foreignContractState($foreignOffice->accountId, (int) $foreignCompany->id, $foreignClientId);
        $detail = $this->getJson('/api/v1/erp-commercial/contracts/'.$foreignContract->id)->assertNotFound();
        foreach (['FOREIGN-CONTRACT-CLIENT-SECRET', 'FOREIGN-CONTRACT-COMPANY-SECRET', '9876.54'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $detail->getContent());
        }
        self::assertSame($before, $this->foreignContractState($foreignOffice->accountId, (int) $foreignCompany->id, $foreignClientId));

        $key = 'foreign-contract-activation-must-not-exist';
        $activation = $this->postJson('/api/v1/erp-commercial/contracts/'.$foreignContract->id.'/activate', [], ['Idempotency-Key' => $key])
            ->assertNotFound();
        foreach (['FOREIGN-CONTRACT-CLIENT-SECRET', 'FOREIGN-CONTRACT-COMPANY-SECRET', '9876.54'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $activation->getContent());
        }

        self::assertSame($before, $this->foreignContractState($foreignOffice->accountId, (int) $foreignCompany->id, $foreignClientId));
        self::assertSame('accepted', $foreignContract->fresh()->agreement_status);
        self::assertNull($foreignSubscription->fresh()->office_erp_contract_id);
        self::assertSame(0, DB::table('office_erp_commercial_operations')->where('idempotency_key', $key)->count());
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function foreignContractState(int $accountId, int $companyId, int $clientId): array
    {
        $state = [];
        $state['clients'] = DB::table('clients')->where('account_id', $accountId)->where('id', $clientId)->get()->map(fn ($row): array => (array) $row)->all();
        foreach ([
            'office_erp_contracts', 'office_erp_contract_revisions', 'office_erp_licence_allocations',
            'office_erp_invoices', 'office_erp_invoice_adjustments', 'office_erp_commercial_operations', 'office_erp_audit_events',
        ] as $table) {
            $state[$table] = DB::table($table)->where('account_id', $accountId)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $state['erp_companies'] = DB::table('erp_companies')->where('id', $companyId)->get()->map(fn ($row): array => (array) $row)->all();
        $state['erp_subscriptions'] = DB::table('erp_subscriptions')->where('erp_company_id', $companyId)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        return $state;
    }

    /** @return array{OfficeErpContract, ErpCompany, ErpSubscription} */
    private function acceptedOccupiedContract($ctx, string $status = 'pending', array $terms = []): array
    {
        $contract = $this->acceptedContract($ctx, $terms);
        $company = ErpCompany::factory()->create();
        $technicalPlan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $isEffective = $status === 'effective';
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $technicalPlan->id,
            'status' => $isEffective ? 'active' : 'pending',
        ]);
        $contract->forceFill([
            'erp_company_id' => $company->id,
            'accepted_at' => now(),
            'agreement_status' => $isEffective ? 'effective' : 'accepted',
            'service_anchor_on' => $isEffective ? now()->toDateString() : null,
            'next_period_index' => $isEffective ? 1 : null,
            'next_period_month_offset' => $isEffective ? 1 : null,
        ])->save();
        OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
            'expires_at' => null,
        ]);

        return [$contract->fresh(), $company, $subscription];
    }
}
