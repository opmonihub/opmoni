<?php

namespace App\Erp\Commercial;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OfficeErpDelinquency
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
    ) {}

    /**
     * @param  array{mode:string,grace_days:int,billing_timezone:string}  $settings
     * @return array{data:list<array<string, mixed>>,meta:array{current_page:int,last_page:int,per_page:int,total:int},affected_count:int}
     */
    public function preview(OfficeErpExecutionContext $ctx, array $settings, int $page = 1): array
    {
        $settings = $this->validateSettings($settings);
        if ($page < 1 || $page > 9007199254740991) {
            throw ValidationException::withMessages(['page' => ['A página informada é inválida.']]);
        }

        $this->admission->assertCommand($ctx, 'policy.read');

        $contracts = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->with(['client', 'company.subscription'])
            ->where('agreement_status', 'effective')
            ->whereNotNull('erp_company_id')
            ->orderBy('id')
            ->get();

        $affected = [];
        foreach ($contracts as $contract) {
            $invoices = $this->issuedInvoices($ctx, $contract);
            $resultingStatus = $this->statusFor($contract, $settings, CarbonImmutable::now(), $invoices);
            if ($resultingStatus !== ErpSubscriptionStatus::PastDue->value) {
                continue;
            }

            $affected[] = $this->previewRow($contract, $settings, $invoices, $resultingStatus);
        }

        $total = count($affected);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $data = $page > $lastPage ? [] : array_slice($affected, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return [
            'data' => $data,
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => self::PER_PAGE, 'total' => $total],
            'affected_count' => $total,
        ];
    }

    /**
     * @param  array{mode:string,grace_days:int,billing_timezone:string}  $settings
     * @return array{data:array{mode:string,grace_days:int,billing_timezone:string}}
     */
    public function save(OfficeErpExecutionContext $ctx, array $settings, bool $confirmAutomatic, string $key): array
    {
        $settings = $this->validateSettings($settings);
        if ($settings['mode'] === 'automatic' && ! $confirmAutomatic) {
            throw ValidationException::withMessages(['confirm_automatic' => ['Confirme explicitamente a ativação da cobrança automática.']]);
        }

        $lockedContracts = new Collection;
        $payload = ['settings' => $settings, 'confirm_automatic' => $confirmAutomatic];

        return $this->operations->execute(
            $ctx,
            $key,
            'policy.configure',
            $payload,
            function (string $action, array $payload) use ($ctx, &$lockedContracts): void {
                $this->admission->assertCommand($ctx, $action);
                $lockedContracts = $this->lockPolicyAggregate($ctx);
            },
            function () use ($ctx, $settings, &$lockedContracts): array {
                $record = OfficeErpRecords::query($ctx, OfficeErpCommercialSetting::class)
                    ->lockForUpdate()
                    ->first();
                if ($record === null) {
                    $record = new OfficeErpCommercialSetting;
                }
                $record->forceFill([
                    'delinquency_policy' => $settings['mode'],
                    'grace_days' => $settings['grace_days'],
                    'billing_timezone' => $settings['billing_timezone'],
                ]);
                OfficeErpRecords::persist($ctx, $record);

                foreach ($lockedContracts as $contract) {
                    $this->reconcileLocked($ctx, $contract->refresh(), $settings, CarbonImmutable::now());
                }

                return ['data' => $this->settingsData($settings)];
            },
        );
    }

    /**
     * @return array{mode:string,grace_days:int,billing_timezone:string}
     */
    public function settingsForOffice(OfficeErpExecutionContext $ctx): array
    {
        $this->admission->assertCommand($ctx, 'policy.read');
        $setting = OfficeErpRecords::query($ctx, OfficeErpCommercialSetting::class)->first();

        return $this->settingsData([
            'mode' => $setting?->delinquency_policy ?? 'manual',
            'grace_days' => (int) ($setting?->grace_days ?? 0),
            'billing_timezone' => $setting?->billing_timezone ?? 'America/Sao_Paulo',
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload): void
    {
        abort_unless($action === 'policy.configure', 422);
        abort_unless(array_keys($payload) === ['confirm_automatic', 'settings'], 409);
        abort_unless(is_array($payload['settings']) && is_bool($payload['confirm_automatic']), 409);
        $settings = $this->validateSettings($payload['settings']);
        abort_unless($settings['mode'] !== 'automatic' || $payload['confirm_automatic'], 409);

        $this->admission->assertCommand($ctx, $action);
        $this->lockPolicyAggregate($ctx);
    }

    public function reconcile(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, CarbonImmutable $now): string
    {
        $ctx->assertVerified();
        abort_unless((int) $contract->account_id === $ctx->accountId, 404);
        abort_unless(DB::transactionLevel() > 0, 409, 'commercial_transaction_required');

        $account = Account::query()->whereKey($ctx->accountId)->lockForUpdate()->firstOrFail();
        abort_unless($account->status === 'active', 403);
        Subscription::query()->where('account_id', $ctx->accountId)->lockForUpdate()->first();
        $companyId = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($contract->getKey())->value('erp_company_id');
        if ($companyId !== null) {
            ErpCompany::query()->whereKey($companyId)->lockForUpdate()->first();
        }
        $lockedContract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();
        OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
            ->where('contract_id', $lockedContract->getKey())->lockForUpdate()->first();
        if ($companyId !== null) {
            ErpSubscription::query()->where('erp_company_id', $companyId)
                ->where('office_erp_contract_id', $lockedContract->getKey())->lockForUpdate()->first();
        }
        $settings = $this->settingsForCalculation($ctx);
        $this->lockContractInvoices($ctx, $lockedContract);

        return $this->reconcileLocked($ctx, $lockedContract, $settings, $now);
    }

    /** @return Collection<int, OfficeErpContract> */
    private function lockPolicyAggregate(OfficeErpExecutionContext $ctx): Collection
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'commercial_transaction_required');
        $account = Account::query()->whereKey($ctx->accountId)->lockForUpdate()->firstOrFail();
        abort_unless($account->status === 'active', 403);
        Subscription::query()->where('account_id', $ctx->accountId)->lockForUpdate()->first();
        OfficeErpRecords::query($ctx, OfficeErpCommercialSetting::class)->lockForUpdate()->first();

        $contractIds = OfficeErpRecords::query($ctx, OfficeErpContract::class)->orderBy('id')->pluck('id');
        $companyIds = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereNotNull('erp_company_id')->orderBy('erp_company_id')->distinct()->pluck('erp_company_id');

        if ($companyIds->isNotEmpty()) {
            ErpCompany::query()->whereIn('id', $companyIds)->orderBy('id')->lockForUpdate()->get(['id']);
            ErpSubscription::query()->whereIn('erp_company_id', $companyIds)
                ->whereIn('office_erp_contract_id', $contractIds)
                ->orderBy('erp_company_id')->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $contracts = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereIn('id', $contractIds)->orderBy('id')->lockForUpdate()->get();
        if ($contractIds->isNotEmpty()) {
            OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->whereIn('contract_id', $contractIds)->orderBy('contract_id')->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $invoiceIds = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->pluck('id');
        if ($invoiceIds->isNotEmpty()) {
            OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)
                ->whereIn('invoice_id', $invoiceIds)->orderBy('invoice_id')->orderBy('id')->lockForUpdate()->get(['id']);
            $receiptAllocationQuery = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
                ->whereIn('invoice_id', $invoiceIds);
            $receiptIds = (clone $receiptAllocationQuery)->orderBy('receipt_id')->distinct()->pluck('receipt_id');
            if ($receiptIds->isNotEmpty()) {
                OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
                    ->whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get(['id']);
                OfficeErpRecords::query($ctx, OfficeErpReceiptReversal::class)
                    ->whereIn('receipt_id', $receiptIds)->orderBy('receipt_id')->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $receiptAllocationQuery->orderBy('id')->lockForUpdate()->get(['id']);
        }

        return $contracts;
    }

    private function lockContractInvoices(OfficeErpExecutionContext $ctx, OfficeErpContract $contract): void
    {
        $invoiceIds = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->where('contract_id', $contract->getKey())->orderBy('id')->lockForUpdate()->pluck('id');
        if ($invoiceIds->isEmpty()) {
            return;
        }

        OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)
            ->whereIn('invoice_id', $invoiceIds)->orderBy('invoice_id')->orderBy('id')->lockForUpdate()->get(['id']);
        $receiptIds = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->whereIn('invoice_id', $invoiceIds)->orderBy('receipt_id')->distinct()->pluck('receipt_id');
        if ($receiptIds->isNotEmpty()) {
            OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
                ->whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get(['id']);
            OfficeErpRecords::query($ctx, OfficeErpReceiptReversal::class)
                ->whereIn('receipt_id', $receiptIds)->orderBy('receipt_id')->orderBy('id')->lockForUpdate()->get(['id']);
        }
        OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->whereIn('invoice_id', $invoiceIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** @param array{mode:string,grace_days:int,billing_timezone:string} $settings */
    private function reconcileLocked(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $settings, CarbonImmutable $now): string
    {
        $invoices = $this->issuedInvoices($ctx, $contract);
        $status = $this->statusFor($contract, $settings, $now, $invoices);

        if ($contract->erp_company_id === null) {
            return $status;
        }

        $subscription = ErpSubscription::query()
            ->where('erp_company_id', $contract->erp_company_id)
            ->where('office_erp_contract_id', $contract->getKey())
            ->first();
        if ($subscription === null || ErpSubscriptionStatus::tryFrom((string) $subscription->status) === null) {
            return $subscription?->status ?? $status;
        }

        if ($subscription->status !== $status) {
            $subscription->forceFill(['status' => $status])->save();
        }

        return $status;
    }

    /** @param list<OfficeErpInvoice> $invoices @param array{mode:string,grace_days:int,billing_timezone:string} $settings */
    private function statusFor(OfficeErpContract $contract, array $settings, CarbonImmutable $now, array $invoices): string
    {
        $subscription = $this->linkedSubscription($contract);
        if ($subscription !== null && ErpSubscriptionStatus::tryFrom((string) $subscription->status) === null) {
            return (string) $subscription->status;
        }
        if ($contract->agreement_status === 'canceled') {
            return ErpSubscriptionStatus::Canceled->value;
        }
        if ($contract->agreement_status !== 'effective' || $contract->erp_company_id === null) {
            return ErpSubscriptionStatus::Pending->value;
        }
        if ($subscription === null) {
            return ErpSubscriptionStatus::Pending->value;
        }
        if ($contract->manual_hold) {
            return ErpSubscriptionStatus::Suspended->value;
        }
        if ($settings['mode'] !== 'automatic') {
            return ErpSubscriptionStatus::Active->value;
        }

        foreach ($invoices as $invoice) {
            $balance = OfficeErpBalances::forInvoice($invoice);
            if (OfficeErpMoney::compare($balance['outstanding'], '0.00') <= 0) {
                continue;
            }
            $timezone = $contract->billing_timezone ?: $settings['billing_timezone'];
            $today = $now->setTimezone($timezone)->toDateString();
            $graceEndsOn = CarbonImmutable::parse($invoice->due_on->toDateString(), $timezone)
                ->addDays($settings['grace_days'])
                ->toDateString();
            if ($today > $graceEndsOn) {
                return ErpSubscriptionStatus::PastDue->value;
            }
        }

        return ErpSubscriptionStatus::Active->value;
    }

    /** @return list<OfficeErpInvoice> */
    private function issuedInvoices(OfficeErpExecutionContext $ctx, OfficeErpContract $contract): array
    {
        return OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->where('contract_id', $contract->getKey())
            ->where('document_status', 'issued')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @param list<OfficeErpInvoice> $invoices @param array{mode:string,grace_days:int,billing_timezone:string} $settings @return array<string, mixed> */
    private function previewRow(OfficeErpContract $contract, array $settings, array $invoices, string $resultingStatus): array
    {
        $outstanding = '0.00';
        $overdue = false;
        foreach ($invoices as $invoice) {
            $balance = OfficeErpBalances::forInvoice($invoice);
            $outstanding = OfficeErpMoney::add($outstanding, $balance['outstanding']);
            if (OfficeErpMoney::compare($balance['outstanding'], '0.00') > 0) {
                $timezone = $contract->billing_timezone ?: $settings['billing_timezone'];
                $today = CarbonImmutable::now($timezone)->toDateString();
                $graceEndsOn = CarbonImmutable::parse($invoice->due_on->toDateString(), $timezone)
                    ->addDays($settings['grace_days'])
                    ->toDateString();
                $overdue = $overdue || $today > $graceEndsOn;
            }
        }

        return [
            'contract_id' => (int) $contract->getKey(),
            'client_name' => (string) $contract->client?->name,
            'company_name' => (string) $contract->company?->name,
            'current_status' => (string) ($this->linkedSubscription($contract)?->status ?? 'pending'),
            'resulting_status' => $resultingStatus,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
        ];
    }

    /** @return array{mode:string,grace_days:int,billing_timezone:string} */
    private function settingsForCalculation(OfficeErpExecutionContext $ctx): array
    {
        $setting = OfficeErpRecords::query($ctx, OfficeErpCommercialSetting::class)->first();

        return $this->settingsData([
            'mode' => $setting?->delinquency_policy ?? 'manual',
            'grace_days' => (int) ($setting?->grace_days ?? 0),
            'billing_timezone' => $setting?->billing_timezone ?? 'America/Sao_Paulo',
        ]);
    }

    private function linkedSubscription(OfficeErpContract $contract): ?ErpSubscription
    {
        $subscription = $contract->company?->subscription;

        return $subscription !== null && (int) $subscription->office_erp_contract_id === (int) $contract->getKey()
            ? $subscription
            : null;
    }

    /** @param array{mode:string,grace_days:int,billing_timezone:string} $settings @return array{mode:string,grace_days:int,billing_timezone:string} */
    private function validateSettings(array $settings): array
    {
        return Validator::make($settings, [
            'mode' => ['required', 'string', 'in:manual,automatic'],
            'grace_days' => ['required', 'integer:strict', 'min:0', 'max:65535'],
            'billing_timezone' => ['required', 'string', 'timezone'],
        ])->validate();
    }

    /** @param array{mode:string,grace_days:int,billing_timezone:string} $settings @return array{mode:string,grace_days:int,billing_timezone:string} */
    private function settingsData(array $settings): array
    {
        return [
            'mode' => (string) $settings['mode'],
            'grace_days' => (int) $settings['grace_days'],
            'billing_timezone' => (string) $settings['billing_timezone'],
        ];
    }
}
