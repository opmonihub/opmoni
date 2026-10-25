<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpCalendar;
use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpInvoices;
use App\Erp\Commercial\OfficeErpRecurringBilling;
use App\Erp\ErpCommercialCatalog;
use App\Jobs\CleanExpiredOfficeErpProofs;
use App\Jobs\GenerateOfficeErpInvoices;
use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;
use Tests\OfficeErpCommercialTestCase;

class RecurringInvoiceTest extends OfficeErpCommercialTestCase
{
    public function test_accepted_unactivated_contract_is_untouched(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);

        $result = app(OfficeErpRecurringBilling::class)->generate(
            (int) $contract->id,
            CarbonImmutable::parse('2026-11-01', 'America/Sao_Paulo'),
        );

        self::assertSame(['generated' => [], 'pending' => false], $result);
        self::assertNull($contract->fresh()->service_anchor_on);
        self::assertNull($contract->fresh()->next_period_index);
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
    }

    public function test_roll_back_cursor_and_restore_context(): void
    {
        CarbonImmutable::setTestNow('2026-02-28 13:00:00');
        $ctx = $this->officeContext();
        [$contract] = $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $beforeCursor = (int) $contract->next_period_index;
        $tenant = app(CurrentTenant::class);
        $tenant->accountId = 987654;

        $attempts = 0;
        OfficeErpInvoice::creating(static function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('recurring invoice failure');
            }
        });

        try {
            app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'));
            self::fail('A recurring invoice failure should roll back the cursor.');
        } catch (\RuntimeException $exception) {
            self::assertSame('recurring invoice failure', $exception->getMessage());
        }

        self::assertSame($beforeCursor, (int) $contract->fresh()->next_period_index);
        self::assertSame(987654, $tenant->accountId);
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());

        $retry = app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'));
        self::assertSame(1, count($retry['generated']));
        self::assertFalse($retry['pending']);
        self::assertSame($beforeCursor + 1, (int) $contract->fresh()->next_period_index);
        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->where('kind', 'recurring')->count());
        self::assertSame('2026-02-28', OfficeErpInvoice::query()->where('contract_id', $contract->id)->firstOrFail()->period_start->toDateString());
        self::assertSame(987654, $tenant->accountId);
    }

    public function test_bounded_catch_up_repeats_with_stable_period_identities(): void
    {
        CarbonImmutable::setTestNow('2026-03-31 13:00:00');
        $ctx = $this->officeContext();
        [$contract] = $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $billing = app(OfficeErpRecurringBilling::class);

        $first = $billing->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'), 1);
        $second = $billing->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'), 1);

        self::assertSame(1, count($first['generated']));
        self::assertTrue($first['pending']);
        self::assertSame(1, count($second['generated']));
        self::assertFalse($second['pending']);
        self::assertSame(2, OfficeErpInvoice::query()->where('contract_id', $contract->id)->where('kind', 'recurring')->count());
        self::assertSame(3, (int) $contract->fresh()->next_period_index);
        self::assertSame(2, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)
            ->where('action', 'billing.generate')->where('actor_kind', 'system')->count());
    }

    public function test_monthly_to_quarterly_amendment_issues_the_next_original_anchor_period(): void
    {
        CarbonImmutable::setTestNow('2026-01-31 13:00:00');
        $ctx = $this->officeContext();
        [$contract, $company, $subscription] = $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $this->issueInitialPeriod($ctx, $contract, $company, $subscription);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend($ctx, $contract, ['interval_months' => 3], 'recurring-monthly-quarterly'));

        CarbonImmutable::setTestNow('2026-02-28 13:00:00');
        app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'));

        $next = OfficeErpInvoice::query()->where('contract_id', $contract->id)->where('kind', 'recurring')->whereDate('period_start', '2026-02-28')->firstOrFail();
        self::assertSame('2026-05-31', $next->period_end->toDateString());
        self::assertSame(2, (int) $contract->fresh()->next_period_index);
        self::assertSame(4, (int) $contract->fresh()->next_period_month_offset);
    }

    public function test_quarterly_to_monthly_amendment_issues_the_next_original_anchor_period(): void
    {
        CarbonImmutable::setTestNow('2026-01-31 13:00:00');
        $ctx = $this->officeContext();
        [$contract, $company, $subscription] = $this->effectiveContract($ctx, '2026-01-31', 3, 3);
        $this->issueInitialPeriod($ctx, $contract, $company, $subscription);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->amend($ctx, $contract, ['interval_months' => 1], 'recurring-quarterly-monthly'));

        CarbonImmutable::setTestNow('2026-04-30 13:00:00');
        app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'));

        $next = OfficeErpInvoice::query()->where('contract_id', $contract->id)->where('kind', 'recurring')->whereDate('period_start', '2026-04-30')->firstOrFail();
        self::assertSame('2026-05-31', $next->period_end->toDateString());
        self::assertSame(2, (int) $contract->fresh()->next_period_index);
        self::assertSame(4, (int) $contract->fresh()->next_period_month_offset);
    }

    public function test_right_loss_still_bills_and_suspended_account_does_not(): void
    {
        CarbonImmutable::setTestNow('2026-02-28 13:00:00');
        $ctx = $this->officeContext();
        [$contract, , $subscription] = $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $contract->forceFill(['manual_hold' => true])->save();
        $subscription->forceFill(['status' => 'suspended'])->save();
        $ctx->account->subscription()->firstOrFail()->forceFill(['status' => 'canceled'])->save();
        app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'));

        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->where('kind', 'recurring')->count());
        self::assertSame('suspended', $subscription->fresh()->status);

        $other = $this->officeContext();
        [$suspended] = $this->effectiveContract($other, '2026-01-31', 1, 1);
        Account::query()->whereKey($other->accountId)->update(['status' => 'suspended']);
        app(OfficeErpRecurringBilling::class)->generate((int) $suspended->id, CarbonImmutable::now('America/Sao_Paulo'));

        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $suspended->id)->count());
    }

    public function test_period_end_cancellation_runs_without_a_new_invoice(): void
    {
        CarbonImmutable::setTestNow('2026-02-28 13:00:00');
        $ctx = $this->officeContext();
        [$contract, , $subscription] = $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $contract->forceFill(['cancel_effective_on' => '2026-02-28'])->save();

        app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'));

        self::assertSame('canceled', $contract->fresh()->agreement_status);
        self::assertSame('released', OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->value('state'));
        self::assertSame('canceled', $subscription->fresh()->status);
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->whereDate('period_start', '2026-02-28')->count());
    }

    public function test_effective_cancellation_keeps_prior_invoice_readable_and_company_history_intact(): void
    {
        CarbonImmutable::setTestNow('2026-01-31 13:00:00');
        $ctx = $this->officeContext();
        [$contract, $company, $subscription] = $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $this->issueInitialPeriod($ctx, $contract, $company, $subscription);
        $invoice = OfficeErpInvoice::query()->where('contract_id', $contract->id)->where('kind', 'recurring')->firstOrFail();
        $companyBefore = $company->fresh()->getAttributes();
        $contractBefore = $contract->fresh()->getAttributes();

        $this->withOfficeAccount($ctx, fn () => app(OfficeErpContracts::class)->cancel(
            $ctx,
            $contract,
            'period_end',
            'Customer requested cancellation',
            'cancel-at-period-end-with-unpaid-invoice',
        ));
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        CarbonImmutable::setTestNow('2026-02-28 13:00:00');
        app(OfficeErpRecurringBilling::class)->generate((int) $contract->id, CarbonImmutable::now('America/Sao_Paulo'));

        $this->getJson('/api/v1/erp-commercial/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.id', (int) $invoice->id)
            ->assertJsonPath('data.document_status', 'issued')
            ->assertJsonPath('data.balance.outstanding', '100.00')
            ->assertJsonPath('data.balance.settlement_status', 'unpaid');

        $this->postJson('/api/v1/erp-commercial/receipts', [
            'amount' => '10.00',
            'received_on' => CarbonImmutable::now('America/Sao_Paulo')->toDateString(),
            'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => '10.00']],
        ], ['Idempotency-Key' => 'receipt-after-effective-contract-cancellation'])
            ->assertCreated()
            ->assertJsonPath('data.current_balances.0.balance.outstanding', '90.00');

        self::assertSame('canceled', $contract->fresh()->agreement_status);
        self::assertSame('released', OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->value('state'));
        self::assertSame('canceled', $subscription->fresh()->status);
        self::assertSame($companyBefore, $company->fresh()->getAttributes());
        self::assertSame((int) $company->id, (int) $contract->fresh()->erp_company_id);
        self::assertSame((int) $contractBefore['current_revision_id'], (int) $contract->fresh()->current_revision_id);
        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        self::assertSame('issued', $invoice->fresh()->document_status);
        self::assertSame('100.00', (string) $invoice->fresh()->original_total);
        $afterReceipt = $this->getJson('/api/v1/erp-commercial/invoices/'.$invoice->id)->assertOk();
        self::assertSame('90.00', $afterReceipt->json('data.balance.outstanding'));
        self::assertSame('partial', $afterReceipt->json('data.balance.settlement_status'));
        self::assertSame('issued', $afterReceipt->json('data.document_status'));
    }

    public function test_scheduled_tick_applies_grace_and_period_end_cancellation_without_new_period(): void
    {
        CarbonImmutable::setTestNow('2026-02-11 13:00:00');
        $ctx = $this->officeContext();
        [$overdue, $company, $subscription] = $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $this->issueInitialPeriod($ctx, $overdue, $company, $subscription);
        OfficeErpCommercialSetting::query()->create([
            'account_id' => $ctx->accountId,
            'delinquency_policy' => 'automatic',
            'grace_days' => 0,
            'billing_timezone' => 'America/Sao_Paulo',
        ]);
        $invoiceCountBefore = OfficeErpInvoice::query()->where('contract_id', $overdue->id)->count();
        app(OfficeErpRecurringBilling::class)->generate((int) $overdue->id, CarbonImmutable::now('America/Sao_Paulo'));

        self::assertSame('past_due', $subscription->fresh()->status);
        self::assertSame($invoiceCountBefore, OfficeErpInvoice::query()->where('contract_id', $overdue->id)->count());

        $other = $this->officeContext();
        [$periodEnd] = $this->effectiveContract($other, '2026-01-31', 1, 1);
        $periodEnd->forceFill(['cancel_effective_on' => '2026-02-11'])->save();
        app(OfficeErpRecurringBilling::class)->generate((int) $periodEnd->id, CarbonImmutable::now('America/Sao_Paulo'));

        self::assertSame('canceled', $periodEnd->fresh()->agreement_status);
        self::assertSame('released', OfficeErpLicenceAllocation::query()->where('contract_id', $periodEnd->id)->value('state'));
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $periodEnd->id)->count());
    }

    public function test_command_discovers_contracts_in_chunks_and_scheduler_registers_once_hourly(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        $this->effectiveContract($ctx, '2026-01-31', 1, 1);
        $this->effectiveContract($ctx, '2026-01-31', 1, 1);

        $this->artisan('office-erp:generate-invoices --batch=1')->assertSuccessful();

        Bus::assertDispatchedTimes(GenerateOfficeErpInvoices::class, 2);
        $events = collect(Schedule::events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'office-erp:generate-invoices --batch=50'),
        );
        self::assertCount(1, $events);
        self::assertTrue($events->first()->withoutOverlapping);
        $proofCleanup = collect(Schedule::events())->filter(
            static fn ($event): bool => $event->description === CleanExpiredOfficeErpProofs::class,
        );
        self::assertCount(1, $proofCleanup);
        self::assertTrue($proofCleanup->first()->withoutOverlapping);
    }

    /** @return array{OfficeErpContract, ErpCompany, ErpSubscription} */
    private function effectiveContract(OfficeErpExecutionContext $ctx, string $anchor, int $interval, int $offset): array
    {
        $contract = $this->acceptedContract($ctx, ['interval_months' => $interval]);
        $company = ErpCompany::factory()->create();
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'active',
        ]);
        $contract->forceFill([
            'erp_company_id' => $company->id,
            'agreement_status' => 'effective',
            'service_anchor_on' => $anchor,
            'effective_at' => now(),
            'next_period_index' => 1,
            'next_period_month_offset' => $offset,
        ])->save();
        $subscription->forceFill(['office_erp_contract_id' => $contract->id])->save();
        OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
            'expires_at' => null,
        ]);

        return [$contract->refresh(), $company, $subscription];
    }

    private function issueInitialPeriod(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, ErpCompany $company, ErpSubscription $subscription): void
    {
        $revision = $contract->currentRevision()->firstOrFail();
        $period = app(OfficeErpCalendar::class)->periodAtMonthOffset(
            $contract->service_anchor_on->toDateString(),
            (int) $revision->interval_months,
            0,
            $contract->billing_timezone,
            (int) $revision->due_in_days,
        );
        app(OfficeErpInvoices::class)->issuePeriod(
            $this->systemContext($ctx->account, 'billing:office-erp-contract:'.$contract->id),
            $contract,
            $revision,
            $period,
            'recurring-initial:'.$contract->id,
        );
        $contract->forceFill(['next_period_index' => 1, 'next_period_month_offset' => (int) $revision->interval_months])->save();
    }
}
