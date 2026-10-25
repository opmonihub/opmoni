<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OfficeErpRecurringBilling
{
    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly OfficeErpCalendar $calendar,
        private readonly OfficeErpContracts $contracts,
        private readonly OfficeErpDelinquency $delinquency,
        private readonly OfficeErpInvoices $invoices,
    ) {}

    /** @return array{generated:list<int>,pending:bool} */
    public function generate(int $contractId, CarbonImmutable $now, int $batchSize = 50): array
    {
        abort_unless($contractId > 0, 404);
        abort_unless($batchSize >= 1 && $batchSize <= self::MAX_BATCH_SIZE, 422, 'invalid_billing_batch');

        $candidate = OfficeErpContract::query()->withoutGlobalScope('account')->findOrFail($contractId);
        $account = Account::query()->findOrFail($candidate->account_id);
        if ($account->status !== 'active') {
            return ['generated' => [], 'pending' => false];
        }

        $ctx = OfficeErpExecutionContext::system($account, 'billing:office-erp-recurring-job:contract:'.$contractId);

        return $ctx->run(function () use ($ctx, $contractId, $now, $batchSize): array {
            $generated = [];

            for ($index = 0; $index < $batchSize; $index++) {
                $result = $this->processNextPeriod($ctx, $contractId, $now, true);
                if ($result['invoice_id'] !== null) {
                    $generated[] = $result['invoice_id'];
                }
                if (! $result['due'] || $result['stopped']) {
                    return ['generated' => $generated, 'pending' => false];
                }
            }

            $next = $this->processNextPeriod($ctx, $contractId, $now, false);

            return ['generated' => $generated, 'pending' => $next['due'] && ! $next['stopped']];
        });
    }

    /** @return array{invoice_id:?int,due:bool,stopped:bool} */
    private function processNextPeriod(OfficeErpExecutionContext $ctx, int $contractId, CarbonImmutable $now, bool $issue): array
    {
        return DB::transaction(function () use ($ctx, $contractId, $now, $issue): array {
            $account = Account::query()->whereKey($ctx->accountId)->lockForUpdate()->firstOrFail();
            if ($account->status !== 'active') {
                return ['invoice_id' => null, 'due' => false, 'stopped' => true];
            }

            $officeSubscription = Subscription::query()->where('account_id', $ctx->accountId)->lockForUpdate()->first();
            if ($officeSubscription?->plan_id !== null) {
                Plan::query()->whereKey($officeSubscription->plan_id)->lockForUpdate()->first();
            }

            $candidate = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contractId)->firstOrFail();
            if ($candidate->erp_company_id !== null) {
                ErpCompany::query()->whereKey($candidate->erp_company_id)->lockForUpdate()->firstOrFail();
            }

            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($contractId)->lockForUpdate()->firstOrFail();
            OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->where('contract_id', $contractId)->lockForUpdate()->first();
            if ($contract->erp_company_id !== null) {
                ErpSubscription::query()->where('erp_company_id', $contract->erp_company_id)
                    ->where('office_erp_contract_id', $contractId)->lockForUpdate()->first();
            }

            OfficeErpInvoiceCounter::query()->where('account_id', $ctx->accountId)
                ->where('year', (int) $now->setTimezone($contract->billing_timezone)->format('Y'))
                ->lockForUpdate()->first();

            $this->contracts->applyDueChanges($ctx, $contract, $now);
            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($contractId)->lockForUpdate()->with('currentRevision')->firstOrFail();
            $this->delinquency->reconcile($ctx, $contract, $now);
            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($contractId)->lockForUpdate()->with('currentRevision')->firstOrFail();

            if ($contract->agreement_status !== 'effective'
                || $contract->service_anchor_on === null
                || $contract->next_period_index === null
                || $contract->next_period_month_offset === null
                || $contract->currentRevision === null) {
                return ['invoice_id' => null, 'due' => false, 'stopped' => true];
            }

            $revision = OfficeErpContractRevision::query()
                ->where('account_id', $ctx->accountId)
                ->where('contract_id', $contractId)
                ->whereKey($contract->current_revision_id)
                ->firstOrFail();
            $period = $this->calendar->periodAtMonthOffset(
                $contract->service_anchor_on->toDateString(),
                (int) $revision->interval_months,
                (int) $contract->next_period_month_offset,
                $contract->billing_timezone,
                (int) $revision->due_in_days,
            );
            if ($period['start'] > $now->setTimezone($contract->billing_timezone)->toDateString()) {
                return ['invoice_id' => null, 'due' => false, 'stopped' => false];
            }
            if (! $issue) {
                return ['invoice_id' => null, 'due' => true, 'stopped' => false];
            }

            $invoice = $this->invoices->issuePeriod(
                $ctx,
                $contract,
                $revision,
                $period,
                sprintf('recurring:%d:%s:%s', $contractId, $period['start'], $period['end']),
            );
            $contract->forceFill([
                'next_period_index' => (int) $contract->next_period_index + 1,
                'next_period_month_offset' => (int) $contract->next_period_month_offset + (int) $revision->interval_months,
            ]);
            OfficeErpRecords::persist($ctx, $contract);

            return ['invoice_id' => (int) $invoice->getKey(), 'due' => true, 'stopped' => false];
        }, 3);
    }
}
