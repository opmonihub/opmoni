<?php

namespace App\Erp\Commercial;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Erp\ErpCoreModules;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpContracts
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
        private readonly OfficeErpInvoices $invoices,
        private readonly OfficeErpDelinquency $delinquency,
        private readonly OfficeErpCalendar $calendar,
    ) {}

    /** @return array{contract_id:int,invoice_id:int,subscription_id:int} */
    public function activate(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $key): array
    {
        $contractId = (int) $contract->getKey();
        $payload = ['command' => 'activate', 'contract_id' => $contractId];
        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'contract.activate',
            $payload,
            fn (string $action, array $value): mixed => $this->authorizeOperation($ctx, $action, $value),
            function () use ($ctx, $contractId, $key): array {
                $contract = $this->contract($ctx, $contractId, true);
                if ($contract->agreement_status === 'effective') {
                    abort(409, 'contract_already_effective');
                }
                abort_unless($contract->agreement_status === 'accepted' && $contract->erp_company_id !== null, 409, 'contract_not_accepted');
                $allocation = $this->allocation($ctx, $contractId, true);
                abort_unless($allocation?->state === 'occupied' && (int) $allocation->erp_company_id === (int) $contract->erp_company_id, 409, 'occupied_allocation_required');
                $now = CarbonImmutable::now($contract->billing_timezone);
                $outcome = $this->activateLocked($ctx, $contract, $now, $this->invoiceOperationKey($key));

                return ['data' => $outcome];
            },
        );

        return $outcome['data'];
    }

    /**
     * Activate an already provisioned administrative or trial contract inside its caller's transaction.
     * The caller owns operation journaling and has locked the commercial aggregate.
     *
     * @return array{contract_id:int,invoice_id:int,subscription_id:int}
     */
    public function activateProvisioned(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, CarbonImmutable $start, string $invoiceOperationKey): array
    {
        abort_unless($ctx->actorKind === 'office_admin', 403);
        abort_unless(in_array($contract->provisioning_source, ['office_admin', 'office_trial'], true), 409, 'provisioned_contract_required');
        abort_unless($contract->agreement_status === 'accepted' && $contract->erp_company_id !== null, 409, 'contract_not_accepted');
        $allocation = $this->allocation($ctx, (int) $contract->id, true);
        abort_unless($allocation?->state === 'occupied' && (int) $allocation->erp_company_id === (int) $contract->erp_company_id, 409, 'occupied_allocation_required');

        return $this->activateLocked($ctx, $contract, $start->setTimezone($contract->billing_timezone), $invoiceOperationKey);
    }

    /** @return array{contract_id:int,invoice_id:int,subscription_id:int} */
    private function activateLocked(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, CarbonImmutable $start, string $invoiceOperationKey): array
    {
        $contractId = (int) $contract->id;
        $company = ErpCompany::query()->whereKey($contract->erp_company_id)->firstOrFail();
        abort_unless($company->status === 'active', 403, 'company_inactive');
        $subscription = ErpSubscription::query()->where('erp_company_id', $company->id)->lockForUpdate()->firstOrFail();
        abort_unless($this->subscriptionCanLink($ctx, $subscription, $contractId), 409, 'company_already_linked');
        abort_unless((int) $contract->current_revision_id > 0, 409, 'contract_revision_missing');
        $revision = $this->revision($ctx, $contract, (int) $contract->current_revision_id);
        $anchor = $start->toDateString();
        $period = $this->calendar->periodAtMonthOffset($anchor, (int) $revision->interval_months, 0, $contract->billing_timezone, (int) $revision->due_in_days);

        $contract->forceFill([
            'agreement_status' => 'effective',
            'service_anchor_on' => $anchor,
            'effective_at' => $start,
            'next_period_index' => 1,
            'next_period_month_offset' => (int) $revision->interval_months,
        ]);
        OfficeErpRecords::persist($ctx, $contract);
        $subscription->forceFill([
            'office_erp_contract_id' => $contractId,
            'status' => in_array($subscription->status, [ErpSubscriptionStatus::Pending->value, ErpSubscriptionStatus::Canceled->value], true)
                ? ErpSubscriptionStatus::Active->value
                : $subscription->status,
        ]);
        $subscription->save();
        $invoice = $this->invoices->issuePeriod($ctx, $contract, $revision, $period, $invoiceOperationKey);

        return ['contract_id' => $contractId, 'invoice_id' => (int) $invoice->getKey(), 'subscription_id' => (int) $subscription->getKey()];
    }

    /**
     * Create the frozen commercial record for direct enrollment or office trial provisioning.
     * Trial callers must leave the linked subscription trialing and must not call activation.
     *
     * @param  array{price:string,currency:'BRL',interval_months:int,due_in_days:int,module_keys:list<string>,billing_timezone?:string}  $terms
     * @return array{contract:OfficeErpContract,revision:OfficeErpContractRevision}
     */
    public function createProvisioned(
        OfficeErpExecutionContext $ctx,
        Client $client,
        ErpCompany $company,
        int $erpPlanId,
        array $terms,
        string $source,
    ): array {
        abort_unless(
            ($source === 'office_admin' && $ctx->actorKind === 'office_admin')
                || ($source === 'office_trial' && $ctx->actorKind === 'owner' && $ctx->purpose === 'trial.register'),
            403,
        );
        $normalized = $this->provisioningTerms($terms);
        $timezone = $terms['billing_timezone'] ?? 'America/Sao_Paulo';
        abort_unless((int) $client->account_id === $ctx->accountId && $client->exists, 404);
        abort_unless($company->exists && $company->status === 'active', 404);

        $contract = new OfficeErpContract;
        $contract->forceFill([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'erp_company_id' => $company->id,
            'agreement_status' => 'accepted',
            'billing_timezone' => $timezone,
            'accepted_at' => CarbonImmutable::now('UTC'),
            'provisioning_source' => $source,
            'erp_plan_id' => $erpPlanId,
            'authorized_by_user_id' => $source === 'office_admin' ? $ctx->actor?->id : null,
            'authorized_at' => $source === 'office_admin' ? CarbonImmutable::now('UTC') : null,
        ]);
        $contract = OfficeErpRecords::persist($ctx, $contract);

        $revision = new OfficeErpContractRevision;
        $revision->forceFill([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'revision_number' => 1,
            'sales_plan_revision_id' => null,
            'price' => $normalized['price'],
            'currency' => 'BRL',
            'interval_months' => $normalized['interval_months'],
            'due_in_days' => $normalized['due_in_days'],
            'module_keys' => $normalized['module_keys'],
            'reason' => $source === 'office_trial' ? 'office_trial_registration' : 'office_admin_enrollment',
            'created_by_user_id' => $ctx->actor?->id,
        ]);
        $revision = OfficeErpRecords::persist($ctx, $revision);
        $contract->forceFill(['current_revision_id' => $revision->id]);
        $contract = OfficeErpRecords::persist($ctx, $contract);

        return ['contract' => $contract, 'revision' => $revision];
    }

    /** @param array<string,mixed> $terms @return array{price:string,interval_months:int,due_in_days:int,module_keys:list<string>} */
    private function provisioningTerms(array $terms): array
    {
        $validated = Validator::make($terms, [
            'price' => ['required', 'string'],
            'currency' => ['required', 'string', Rule::in(['BRL'])],
            'interval_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'due_in_days' => ['required', 'integer', 'between:0,65535'],
            'module_keys' => ['required', 'array', 'list'],
            'module_keys.*' => ['required', 'string', 'distinct:strict', Rule::in(ErpCoreModules::BASIC)],
        ])->validate();
        $validated['price'] = OfficeErpMoney::canonical($validated['price'], 'price');
        sort($validated['module_keys'], SORT_STRING);

        return $validated;
    }

    /** @param array<string, mixed> $terms */
    public function amend(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $terms, string $key): OfficeErpContractRevision
    {
        $terms = $this->terms($ctx, $contract, $terms);
        $current = $this->revision($ctx, $contract, (int) $contract->current_revision_id);
        $expands = array_diff($terms['module_keys'], $current->module_keys ?? []) !== [];
        $action = $this->existingAmendmentAction($ctx, $key) ?? ($expands ? 'modules.expand' : 'contract.amend.price');
        $payload = ['command' => 'amend', 'contract_id' => (int) $contract->id, 'terms' => $terms];
        $outcome = $this->operations->execute(
            $ctx,
            $key,
            $action,
            $payload,
            fn (string $action, array $value): mixed => $this->authorizeOperation($ctx, $action, $value),
            fn (): array => ['data' => ['revision_id' => (int) $this->createPendingRevision($ctx, $contract, $terms, 'amendment')->id]],
        );

        return $this->revision($ctx, $contract, (int) $outcome['data']['revision_id']);
    }

    public function renew(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $key): OfficeErpContractRevision
    {
        $current = $this->revision($ctx, $contract, (int) $contract->current_revision_id);
        $terms = [
            'price' => OfficeErpMoney::canonical((string) $current->getRawOriginal('price'), 'price'),
            'interval_months' => (int) $current->interval_months,
            'due_in_days' => (int) $current->due_in_days,
            'module_keys' => $current->module_keys ?? [],
        ];
        $payload = ['command' => 'renew', 'contract_id' => (int) $contract->id];
        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'contract.renew',
            $payload,
            fn (string $action, array $value): mixed => $this->authorizeOperation($ctx, $action, $value),
            fn (): array => ['data' => ['revision_id' => (int) $this->createPendingRevision($ctx, $contract, $terms, 'renewal')->id]],
        );

        return $this->revision($ctx, $contract, (int) $outcome['data']['revision_id']);
    }

    public function suspend(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $reason, string $key): void
    {
        $reason = $this->reason($reason);
        $this->command($ctx, $contract, $key, 'contract.suspend', 'suspend', ['reason' => $reason], function (OfficeErpContract $locked) use ($ctx): array {
            abort_unless($locked->agreement_status === 'effective', 409, 'contract_not_effective');
            $locked->forceFill(['manual_hold' => true]);
            OfficeErpRecords::persist($ctx, $locked);
            $this->linkedSubscription($locked)->forceFill(['status' => ErpSubscriptionStatus::Suspended->value])->save();

            return ['contract_id' => (int) $locked->id, 'agreement_status' => $locked->agreement_status, 'manual_hold' => true];
        });
    }

    public function resume(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $key): void
    {
        $this->command($ctx, $contract, $key, 'contract.resume', 'resume', [], function (OfficeErpContract $locked) use ($ctx): array {
            abort_unless($locked->agreement_status === 'effective', 409, 'contract_not_effective');
            $locked->forceFill(['manual_hold' => false]);
            OfficeErpRecords::persist($ctx, $locked);
            $company = ErpCompany::query()->whereKey($locked->erp_company_id)->firstOrFail();
            if ($company->status === 'active') {
                $this->delinquency->reconcile($ctx, $locked, CarbonImmutable::now($locked->billing_timezone));
            }

            return ['contract_id' => (int) $locked->id, 'agreement_status' => $locked->agreement_status, 'manual_hold' => false];
        });
    }

    public function cancel(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $mode, string $reason, string $key): void
    {
        abort_unless(in_array($mode, ['immediate', 'period_end'], true), 422, 'invalid_cancellation_mode');
        $reason = $this->reason($reason);
        $payload = ['command' => 'cancel', 'contract_id' => (int) $contract->id, 'mode' => $mode, 'reason' => $reason];
        $this->command($ctx, $contract, $key, 'contract.cancel', 'cancel', ['mode' => $mode, 'reason' => $reason], function (OfficeErpContract $locked) use ($ctx, $mode, $reason): array {
            abort_unless(in_array($locked->agreement_status, ['accepted', 'effective'], true), 409, 'contract_not_cancelable');
            $now = CarbonImmutable::now($locked->billing_timezone);
            $effectiveOn = $mode === 'immediate'
                ? $now->toDateString()
                : $this->nextPeriodStart($locked);
            $locked->forceFill(['cancel_effective_on' => $effectiveOn, 'cancellation_reason' => $reason]);
            OfficeErpRecords::persist($ctx, $locked);
            if ($mode === 'immediate') {
                $this->applyCancellation($ctx, $locked, $now);
            }

            return ['contract_id' => (int) $locked->id, 'agreement_status' => $locked->fresh()->agreement_status, 'cancel_effective_on' => $effectiveOn];
        }, $payload);
    }

    public function applyDueChanges(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, CarbonImmutable $now): void
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'commercial_transaction_required');
        abort_unless((int) $contract->account_id === $ctx->accountId, 404);
        $locked = $this->contract($ctx, (int) $contract->getKey(), true);
        if ($locked->agreement_status !== 'effective') {
            return;
        }
        $localDate = $now->setTimezone($locked->billing_timezone)->toDateString();
        if ($locked->cancel_effective_on !== null && $locked->cancel_effective_on->toDateString() <= $localDate) {
            $this->applyCancellation($ctx, $locked, $now);

            return;
        }
        if ($locked->pending_revision_id === null) {
            return;
        }
        $pending = $this->revision($ctx, $locked, (int) $locked->pending_revision_id);
        if ($pending->service_period_start === null || $pending->service_period_start->toDateString() > $localDate) {
            return;
        }
        $locked->forceFill([
            'current_revision_id' => $pending->id,
            'pending_revision_id' => null,
        ]);
        OfficeErpRecords::persist($ctx, $locked);
    }

    /** @return array<string, mixed> */
    public function lookup(OfficeErpExecutionContext $ctx, string $key): array
    {
        return $this->operations->lookup(
            $ctx,
            $key,
            fn (string $action, array $payload): mixed => $this->authorizeOperation($ctx, $action, $payload, $key),
        );
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function index(OfficeErpExecutionContext $ctx, array $filters = []): array
    {
        $this->admission->assertCommand($ctx, 'contract.read');
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $query = OfficeErpRecords::query($ctx, OfficeErpContract::class)->with([
            'client', 'company.subscription.trial', 'company.subscription.plan', 'currentRevision', 'pendingRevision',
        ]);
        if (($filters['agreement_status'] ?? '') !== '') {
            $query->where('agreement_status', $filters['agreement_status']);
        }
        if (($filters['q'] ?? '') !== '') {
            $term = trim((string) $filters['q']);
            $query->whereHas('client', fn (Builder $clients): Builder => $clients->where('name', 'like', '%'.$term.'%'));
        }
        $page = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        return [
            'data' => collect($page->items())->map(fn (OfficeErpContract $contract): array => $this->contractData($ctx, $contract))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    public function show(OfficeErpExecutionContext $ctx, int $contractId): array
    {
        $contract = $this->contract($ctx, $contractId)->load([
            'client', 'company.subscription.trial', 'company.subscription.plan', 'currentRevision', 'pendingRevision', 'revisions',
        ]);
        $this->admission->assertCommand($ctx, 'contract.read', $contract);

        return ['data' => $this->contractData($ctx, $contract, true)];
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, string $key = ''): void
    {
        $command = $payload['command'] ?? null;
        abort_unless(is_string($command), 409);
        $id = $payload['contract_id'] ?? null;
        abort_unless(is_int($id) && $id > 0, 409);
        $expected = match ($command) {
            'activate' => 'contract.activate',
            'amend' => in_array($action, ['contract.amend.price', 'modules.expand'], true) ? $action : null,
            'renew' => 'contract.renew',
            'suspend' => 'contract.suspend',
            'resume' => 'contract.resume',
            'cancel' => 'contract.cancel',
            default => null,
        };
        abort_unless($expected !== null && $action === $expected, 409);

        $candidate = $this->contract($ctx, $id);
        [$account] = $this->admission->lockCommercialAggregate($ctx);
        abort_unless($account->status === 'active', 403);
        if ($candidate->erp_company_id !== null) {
            $company = ErpCompany::query()->whereKey($candidate->erp_company_id)->lockForUpdate()->firstOrFail();
            $subscription = ErpSubscription::query()->where('erp_company_id', $company->id)->lockForUpdate()->first();
        } else {
            $company = null;
            $subscription = null;
        }
        $locked = $this->contract($ctx, $id, true)->load('currentRevision');
        $allocation = $this->allocation($ctx, $id, true);
        $authorizationAction = $action;
        if (in_array($action, ['contract.amend.price', 'modules.expand'], true)) {
            abort_unless(is_array($payload['terms'] ?? null), 409);
            $terms = $this->terms($ctx, $locked, $payload['terms']);
            $currentModules = $locked->currentRevision?->module_keys ?? [];
            $authorizationAction = array_diff($terms['module_keys'], $currentModules) === [] ? 'contract.amend.price' : 'modules.expand';
        }
        if (in_array($authorizationAction, ['contract.renew', 'contract.amend.price', 'modules.expand', 'contract.suspend', 'contract.resume'], true)
            || ($action === 'contract.activate' && $locked->agreement_status === 'accepted')) {
            $this->admission->assertCommand($ctx, $authorizationAction, $locked, $payload['terms']['module_keys'] ?? []);
        }
        if ($action === 'contract.activate') {
            abort_unless($locked->erp_company_id !== null && $allocation?->state === 'occupied'
                && (int) $allocation->erp_company_id === (int) $locked->erp_company_id, 409, 'occupied_allocation_required');
            abort_unless($subscription !== null && $this->subscriptionCanLink($ctx, $subscription, $id), 409, 'company_subscription_unavailable');
            abort_unless($company?->status === 'active', 403, 'company_inactive');
            abort_unless(in_array($locked->agreement_status, ['accepted', 'effective'], true), 409, 'contract_not_accepted');
            $revision = $this->revision($ctx, $locked, (int) $locked->current_revision_id);
            $now = CarbonImmutable::now($locked->billing_timezone);
            $anchor = ($locked->service_anchor_on?->toDateString()) ?? $now->toDateString();
            $period = $this->calendar->periodAtMonthOffset($anchor, (int) $revision->interval_months, 0, $locked->billing_timezone, (int) $revision->due_in_days);
            $this->lockInvoiceCounter($ctx, (int) $now->format('Y'));
            $this->lockInvoiceAggregate($ctx, $id);
            $existing = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
                ->where('contract_id', $id)->where('kind', 'recurring')
                ->whereDate('period_start', $period['start'])->whereDate('period_end', $period['end'])
                ->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless($locked->agreement_status === 'effective'
                    && (int) $existing->contract_revision_id === (int) $revision->id
                    && $existing->document_status === 'issued', 409, 'invoice_period_reserved');
            }
        }
        if ($action === 'contract.cancel') {
            abort_unless(is_string($payload['mode'] ?? null) && in_array($payload['mode'], ['immediate', 'period_end'], true), 409);
            $this->reason($payload['reason'] ?? null);
            abort_unless(in_array($locked->agreement_status, ['accepted', 'effective', 'canceled'], true), 409, 'contract_not_cancelable');
        }
        if ($action === 'contract.suspend' || $action === 'contract.resume') {
            abort_unless($locked->agreement_status === 'effective', 409, 'contract_not_effective');
        }
        if (in_array($action, ['contract.amend.price', 'modules.expand'], true)) {
            abort_unless($locked->agreement_status === 'effective', 409, 'contract_not_effective');
        }
        if ($action === 'contract.renew') {
            abort_unless($locked->agreement_status === 'effective', 409, 'contract_not_effective');
        }
    }

    /** @param array<string, mixed> $meta */
    private function command(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $key, string $action, string $name, array $meta, callable $effect, ?array $payload = null): void
    {
        $payload ??= ['command' => $name, 'contract_id' => (int) $contract->id, ...$meta];
        $this->operations->execute(
            $ctx,
            $key,
            $action,
            $payload,
            fn (string $currentAction, array $value): mixed => $this->authorizeOperation($ctx, $currentAction, $value),
            fn (): array => ['data' => $effect($this->contract($ctx, (int) $contract->id, true))],
        );
    }

    /** @param array<string, mixed> $terms */
    private function createPendingRevision(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $terms, string $reason): OfficeErpContractRevision
    {
        $locked = $this->contract($ctx, (int) $contract->id, true);
        abort_unless($locked->agreement_status === 'effective' && $locked->pending_revision_id === null, 409, 'contract_change_pending');
        $current = $this->revision($ctx, $locked, (int) $locked->current_revision_id);
        $offset = (int) $locked->next_period_month_offset;
        $period = $this->calendar->periodAtMonthOffset($locked->service_anchor_on->toDateString(), $terms['interval_months'], $offset, $locked->billing_timezone, $terms['due_in_days']);
        $number = (int) $locked->revisions()->max('revision_number') + 1;
        $revision = OfficeErpRecords::persist($ctx, new OfficeErpContractRevision([
            'account_id' => $ctx->accountId,
            'contract_id' => $locked->id,
            'revision_number' => $number,
            'sales_plan_revision_id' => $current->sales_plan_revision_id,
            'price' => $terms['price'],
            'currency' => 'BRL',
            'interval_months' => $terms['interval_months'],
            'due_in_days' => $terms['due_in_days'],
            'module_keys' => $terms['module_keys'],
            'service_period_start' => $period['start'],
            'service_period_end' => $period['end'],
            'reason' => $reason,
            'created_by_user_id' => $ctx->actor?->getKey(),
        ]));
        $locked->forceFill(['pending_revision_id' => $revision->id]);
        OfficeErpRecords::persist($ctx, $locked);

        return $revision;
    }

    /** @param array<string, mixed> $terms @return array{price:string,interval_months:int,due_in_days:int,module_keys:list<string>} */
    private function terms(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $terms): array
    {
        abort_unless(array_diff(array_keys($terms), ['price', 'interval_months', 'due_in_days', 'module_keys']) === [], 422, 'invalid_contract_terms');
        $current = $this->revision($ctx, $contract, (int) $contract->current_revision_id);
        $merged = [
            'price' => $terms['price'] ?? OfficeErpMoney::canonical((string) $current->getRawOriginal('price'), 'price'),
            'interval_months' => $terms['interval_months'] ?? (int) $current->interval_months,
            'due_in_days' => $terms['due_in_days'] ?? (int) $current->due_in_days,
            'module_keys' => $terms['module_keys'] ?? ($current->module_keys ?? []),
        ];
        $validated = Validator::make($merged, [
            'price' => ['required', 'string'],
            'interval_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'due_in_days' => ['required', 'integer', 'between:0,65535'],
            'module_keys' => ['required', 'array', 'list'],
            'module_keys.*' => ['required', 'string', 'distinct:strict', Rule::in(ErpCoreModules::BASIC)],
        ])->validate();
        $validated['price'] = OfficeErpMoney::canonical($validated['price'], 'price');
        sort($validated['module_keys'], SORT_STRING);

        return $validated;
    }

    private function applyCancellation(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, CarbonImmutable $now): void
    {
        if ($contract->agreement_status === 'canceled') {
            return;
        }
        $localDate = $now->setTimezone($contract->billing_timezone)->toDateString();
        if ($contract->cancel_effective_on !== null && $contract->cancel_effective_on->toDateString() > $localDate) {
            return;
        }
        $contract->forceFill(['agreement_status' => 'canceled', 'canceled_at' => $now]);
        OfficeErpRecords::persist($ctx, $contract);
        if ($contract->erp_company_id !== null) {
            $subscription = ErpSubscription::query()->where('erp_company_id', $contract->erp_company_id)->first();
            if ($subscription !== null && (int) $subscription->office_erp_contract_id === (int) $contract->id) {
                $subscription->forceFill(['status' => ErpSubscriptionStatus::Canceled->value])->save();
            }
            $allocation = $this->allocation($ctx, (int) $contract->id, true);
            if ($allocation?->state === 'occupied') {
                $allocation->forceFill(['state' => 'released', 'released_at' => $now]);
                OfficeErpRecords::persist($ctx, $allocation);
            }
        }
    }

    private function nextPeriodStart(OfficeErpContract $contract): string
    {
        abort_unless($contract->service_anchor_on !== null && $contract->next_period_month_offset !== null, 409, 'contract_period_unavailable');

        return $this->calendar->periodAtMonthOffset(
            $contract->service_anchor_on->toDateString(),
            (int) $contract->currentRevision->interval_months,
            (int) $contract->next_period_month_offset,
            $contract->billing_timezone,
            (int) $contract->currentRevision->due_in_days,
        )['start'];
    }

    /** @return array<string, mixed> */
    private function contractData(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, bool $detail = false): array
    {
        $invoices = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)->where('contract_id', $contract->id)->orderByDesc('id')->get();
        $balances = array_map(static fn (OfficeErpInvoice $invoice): array => OfficeErpBalances::forInvoice($invoice), $invoices->all());
        $outstanding = '0.00';
        foreach ($balances as $balance) {
            $outstanding = OfficeErpMoney::add($outstanding, $balance['outstanding']);
        }
        $current = $contract->currentRevision;
        $subscription = $contract->company?->subscription;
        $subscriptionSummary = $this->linkedSubscriptionSummary($contract, $subscription);
        $data = [
            'id' => (int) $contract->id,
            'client' => ['id' => (int) $contract->client_id, 'name' => (string) $contract->client?->name],
            'company' => $contract->company === null ? null : [
                'id' => (int) $contract->company->id,
                'name' => (string) $contract->company->name,
                'owner_setup_status' => (string) $contract->company->owner_setup_status,
            ],
            'agreement_status' => (string) $contract->agreement_status,
            ...$subscriptionSummary,
            'current_revision' => $current === null ? null : $this->revisionData($current),
            'pending_revision' => $contract->pendingRevision === null ? null : $this->revisionData($contract->pendingRevision),
            'service_anchor_on' => $contract->service_anchor_on?->toDateString(),
            'billing_timezone' => (string) $contract->billing_timezone,
            'next_period_index' => $contract->next_period_index,
            'next_period_month_offset' => $contract->next_period_month_offset,
            'cancel_effective_on' => $contract->cancel_effective_on?->toDateString(),
            'cancellation_reason' => $contract->cancellation_reason,
            'manual_hold' => (bool) $contract->manual_hold,
            'balance' => ['outstanding' => $outstanding, 'invoice_count' => count($invoices)],
        ];
        if ($detail) {
            $data['revisions'] = $contract->revisions->sortBy('revision_number')->map(fn (OfficeErpContractRevision $revision): array => $this->revisionData($revision))->values()->all();
            $data['invoice_ids'] = $invoices->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        return $data;
    }

    /** @return array{licence_status:?string,erp_plan_id:?int,erp_plan:?array{id:int,name:string},trial:?array,next_billing_on:?string} */
    public function linkedSubscriptionSummary(OfficeErpContract $contract, ?ErpSubscription $subscription): array
    {
        $linked = $subscription !== null && (int) $subscription->office_erp_contract_id === (int) $contract->id;
        if (! $linked) {
            return [
                'licence_status' => null,
                'erp_plan_id' => null,
                'erp_plan' => null,
                'trial' => null,
                'next_billing_on' => null,
            ];
        }

        $subscription->loadMissing(['trial', 'plan']);
        $planId = $contract->erp_plan_id === null ? (int) $subscription->erp_plan_id : (int) $contract->erp_plan_id;

        return [
            'licence_status' => (string) $subscription->status,
            'erp_plan_id' => $planId,
            'erp_plan' => $subscription->plan === null ? null : [
                'id' => $planId,
                'name' => (string) $subscription->plan->name,
            ],
            'trial' => ErpTrialSummary::forLinkedContract($subscription, $contract),
            'next_billing_on' => $this->nextBillingOn($contract, $subscription),
        ];
    }

    private function nextBillingOn(OfficeErpContract $contract, ErpSubscription $subscription): ?string
    {
        if ($subscription->status === ErpSubscriptionStatus::Trialing->value
            || $contract->agreement_status !== 'effective'
            || $contract->service_anchor_on === null
            || $contract->next_period_month_offset === null
            || $contract->currentRevision === null) {
            return null;
        }

        $now = CarbonImmutable::now($contract->billing_timezone);
        $revision = $contract->pendingRevision;
        if ($revision === null || $revision->service_period_start === null
            || $revision->service_period_start->toDateString() > $now->toDateString()) {
            $revision = $contract->currentRevision;
        }

        $period = $this->calendar->periodAtMonthOffset(
            $contract->service_anchor_on->toDateString(),
            (int) $revision->interval_months,
            (int) $contract->next_period_month_offset,
            $contract->billing_timezone,
            (int) $revision->due_in_days,
        );
        if ($contract->cancel_effective_on !== null
            && $contract->cancel_effective_on->toDateString() <= $period['start']) {
            return null;
        }

        return $period['due_on'];
    }

    /** @return array<string, mixed> */
    public function revisionData(OfficeErpContractRevision $revision): array
    {
        return [
            'id' => (int) $revision->id,
            'revision_number' => (int) $revision->revision_number,
            'price' => OfficeErpMoney::canonical((string) $revision->getRawOriginal('price'), 'price'),
            'currency' => (string) $revision->currency,
            'interval_months' => (int) $revision->interval_months,
            'due_in_days' => (int) $revision->due_in_days,
            'module_keys' => $revision->module_keys ?? [],
            'service_period_start' => $revision->service_period_start?->toDateString(),
            'service_period_end' => $revision->service_period_end?->toDateString(),
            'reason' => $revision->reason,
        ];
    }

    private function reason(?string $reason): string
    {
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => ['Informe um motivo de até 2.000 caracteres.']]);
        }

        return trim($reason);
    }

    private function invoiceOperationKey(string $key): string
    {
        return 'activate-invoice-'.substr(hash('sha256', $key), 0, 48);
    }

    private function existingAmendmentAction(OfficeErpExecutionContext $ctx, string $key): ?string
    {
        $operation = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)
            ->first(['action']);

        return in_array($operation?->action, ['contract.amend.price', 'modules.expand'], true)
            ? (string) $operation->action
            : null;
    }

    private function lockInvoiceCounter(OfficeErpExecutionContext $ctx, int $year): void
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpInvoiceCounter::class)->where('year', $year);
        if ($query->lockForUpdate()->first() !== null) {
            return;
        }
        try {
            OfficeErpRecords::persist($ctx, new OfficeErpInvoiceCounter([
                'account_id' => $ctx->accountId,
                'year' => $year,
                'next_number' => 1,
            ]));
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)
                || ! str_contains(strtolower($exception->getMessage()), 'office_erp_invoice_counters')) {
                throw $exception;
            }
        }
        $query->lockForUpdate()->firstOrFail();
    }

    private function lockInvoiceAggregate(OfficeErpExecutionContext $ctx, int $contractId): void
    {
        $invoiceIds = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->where('contract_id', $contractId)->orderBy('id')->lockForUpdate()->pluck('id');
        if ($invoiceIds->isEmpty()) {
            return;
        }
        OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)
            ->whereIn('invoice_id', $invoiceIds)->orderBy('invoice_id')->orderBy('id')->lockForUpdate()->get(['id']);
        $receiptIds = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->whereIn('invoice_id', $invoiceIds)->orderBy('receipt_id')->distinct()->pluck('receipt_id');
        if ($receiptIds->isEmpty()) {
            return;
        }
        OfficeErpRecords::query($ctx, OfficeErpReceipt::class)->whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get(['id']);
        OfficeErpRecords::query($ctx, OfficeErpReceiptReversal::class)->whereIn('receipt_id', $receiptIds)->orderBy('receipt_id')->lockForUpdate()->get(['id']);
        OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)->whereIn('invoice_id', $invoiceIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function linkedSubscription(OfficeErpContract $contract): ErpSubscription
    {
        return ErpSubscription::query()->where('erp_company_id', $contract->erp_company_id)
            ->where('office_erp_contract_id', $contract->id)->firstOrFail();
    }

    private function subscriptionCanLink(OfficeErpExecutionContext $ctx, ErpSubscription $subscription, int $contractId): bool
    {
        $previousContractId = $subscription->office_erp_contract_id;
        if ($previousContractId === null || (int) $previousContractId === $contractId) {
            return true;
        }

        $previous = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($previousContractId)
            ->where('erp_company_id', $subscription->erp_company_id)
            ->first();
        if ($previous?->agreement_status !== 'canceled') {
            return false;
        }

        return $this->allocation($ctx, (int) $previous->id)?->state === 'released';
    }

    private function contract(OfficeErpExecutionContext $ctx, int $id, bool $lock = false): OfficeErpContract
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($id)->with(['currentRevision', 'pendingRevision', 'client', 'company.subscription']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function revision(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, int $revisionId): OfficeErpContractRevision
    {
        return OfficeErpRecords::query($ctx, OfficeErpContractRevision::class)
            ->where('contract_id', $contract->id)->whereKey($revisionId)->firstOrFail();
    }

    private function allocation(OfficeErpExecutionContext $ctx, int $contractId, bool $lock = false): ?OfficeErpLicenceAllocation
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)->where('contract_id', $contractId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
