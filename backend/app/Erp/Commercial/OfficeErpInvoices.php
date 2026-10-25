<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OfficeErpInvoices
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
        private readonly OfficeErpDelinquency $delinquency,
    ) {}

    /** @param array<string, mixed> $input */
    public function draft(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $input, string $key): OfficeErpInvoice
    {
        $input = $this->manualInput($input);
        $contractId = (int) $contract->getKey();
        $payload = ['command' => 'draft', 'contract_id' => $contractId, 'input' => $input];

        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'invoice.issue',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload),
            function () use ($ctx, $contractId, $input): array {
                $contract = $this->contract($ctx, $contractId, true);
                abort_unless($contract->agreement_status === 'effective', 409, 'contract_not_effective');
                $revision = $this->currentRevision($ctx, $contract);
                $invoice = OfficeErpRecords::persist($ctx, new OfficeErpInvoice([
                    'account_id' => $ctx->accountId,
                    'contract_id' => $contract->id,
                    'contract_revision_id' => $revision->id,
                    'kind' => 'manual',
                    'document_status' => 'draft',
                    'period_start' => $input['period_start'],
                    'period_end' => $input['period_end'],
                    'due_on' => $input['due_on'],
                    'currency' => $revision->currency,
                    'seller_snapshot' => [],
                    'bill_to_snapshot' => [],
                    'items_snapshot' => $input['items'],
                    'original_total' => $input['original_total'],
                ]));

                return ['data' => $this->invoiceData($invoice)];
            },
        );

        return $this->invoiceFromOutcome($ctx, $outcome);
    }

    /** @param array<string, mixed> $input */
    public function updateDraft(OfficeErpExecutionContext $ctx, OfficeErpInvoice $invoice, array $input, string $key): OfficeErpInvoice
    {
        abort_unless((int) $invoice->account_id === $ctx->accountId, 404);
        $invoiceId = (int) $invoice->getKey();
        $input = $this->manualInput(array_replace($this->manualInputFromInvoice($invoice), $input));
        $payload = ['command' => 'update_draft', 'invoice_id' => $invoiceId, 'input' => $input];

        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'invoice.issue',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload),
            function () use ($ctx, $invoiceId, $input): array {
                $invoice = $this->invoice($ctx, $invoiceId, true);
                abort_unless($invoice->document_status === 'draft' && $invoice->kind === 'manual', 409, 'invoice_not_editable');
                $invoice->forceFill([
                    'period_start' => $input['period_start'],
                    'period_end' => $input['period_end'],
                    'due_on' => $input['due_on'],
                    'items_snapshot' => $input['items'],
                    'original_total' => $input['original_total'],
                ]);
                OfficeErpRecords::persist($ctx, $invoice);

                return ['data' => $this->invoiceData($invoice->refresh())];
            },
        );

        return $this->invoiceFromOutcome($ctx, $outcome);
    }

    public function issue(OfficeErpExecutionContext $ctx, OfficeErpInvoice $invoice, string $key): OfficeErpInvoice
    {
        abort_unless((int) $invoice->account_id === $ctx->accountId, 404);
        $invoiceId = (int) $invoice->getKey();
        $payload = ['command' => 'issue', 'invoice_id' => $invoiceId];

        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'invoice.issue',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload),
            function () use ($ctx, $invoiceId): array {
                $invoice = $this->invoice($ctx, $invoiceId, true);
                abort_unless($invoice->document_status === 'draft' && $invoice->kind === 'manual', 409, 'invoice_not_draft');
                $contract = $this->contract($ctx, (int) $invoice->contract_id, true);
                abort_unless($contract->agreement_status === 'effective', 409, 'contract_not_effective');
                $revision = $this->revision($ctx, $contract, (int) $invoice->contract_revision_id);
                $issueAt = CarbonImmutable::now($contract->billing_timezone);
                $number = OfficeErpInvoiceCounter::reserveNumber($ctx, (int) $issueAt->format('Y'));
                $invoice->forceFill([
                    'document_status' => 'issued',
                    'number' => $number,
                    'issued_at' => $issueAt,
                    'currency' => $revision->currency,
                    'seller_snapshot' => $this->sellerSnapshot($ctx, $contract, $revision),
                    'bill_to_snapshot' => $this->billToSnapshot($ctx, $contract),
                ]);
                OfficeErpRecords::persist($ctx, $invoice);
                $invoice = $invoice->refresh()->load(['contract', 'adjustments.correctionOf']);
                $this->delinquency->reconcile($ctx, $contract, CarbonImmutable::now());

                return ['data' => $this->invoiceData($invoice)];
            },
        );

        return $this->invoiceFromOutcome($ctx, $outcome);
    }

    /** @param array{start:string,end:string,due_on:string} $period */
    public function issuePeriod(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, OfficeErpContractRevision $revision, array $period, string $key): OfficeErpInvoice
    {
        $contractId = (int) $contract->getKey();
        $revisionId = (int) $revision->getKey();
        $period = $this->periodInput($period);
        $payload = ['command' => 'issue_period', 'contract_id' => $contractId, 'revision_id' => $revisionId, 'period' => $period];

        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'invoice.issue',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload),
            function () use ($ctx, $contractId, $revisionId, $period): array {
                $contract = $this->contract($ctx, $contractId, true);
                abort_unless($contract->agreement_status === 'effective', 409, 'contract_not_effective');
                $revision = $this->revision($ctx, $contract, $revisionId);
                abort_unless((int) $contract->current_revision_id === $revisionId, 409, 'contract_revision_stale');
                $existing = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
                    ->where('contract_id', $contractId)
                    ->where('kind', 'recurring')
                    ->whereDate('period_start', $period['start'])
                    ->whereDate('period_end', $period['end'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    abort_unless(
                        $existing->document_status === 'issued'
                            && (int) $existing->contract_revision_id === $revisionId
                            && $existing->due_on->toDateString() === $period['due_on'],
                        409,
                        'invoice_period_reserved',
                    );

                    return ['data' => $this->invoiceData($existing->load(['contract', 'adjustments.correctionOf']))];
                }

                $issueAt = CarbonImmutable::now($contract->billing_timezone);
                $number = OfficeErpInvoiceCounter::reserveNumber($ctx, (int) $issueAt->format('Y'));
                $amount = OfficeErpMoney::canonical((string) $revision->price, 'price');
                $invoice = OfficeErpRecords::persist($ctx, new OfficeErpInvoice([
                    'account_id' => $ctx->accountId,
                    'contract_id' => $contractId,
                    'contract_revision_id' => $revisionId,
                    'kind' => 'recurring',
                    'document_status' => 'issued',
                    'number' => $number,
                    'issued_at' => $issueAt,
                    'period_start' => $period['start'],
                    'period_end' => $period['end'],
                    'due_on' => $period['due_on'],
                    'currency' => $revision->currency,
                    'seller_snapshot' => $this->sellerSnapshot($ctx, $contract, $revision),
                    'bill_to_snapshot' => $this->billToSnapshot($ctx, $contract),
                    'items_snapshot' => [['description' => 'Serviço contratado', 'amount' => $amount, 'discount' => '0.00']],
                    'original_total' => $amount,
                ]));
                $invoice = $invoice->load(['contract', 'adjustments.correctionOf']);
                $this->delinquency->reconcile($ctx, $contract, CarbonImmutable::now());

                return ['data' => $this->invoiceData($invoice)];
            },
        );

        return $this->invoiceFromOutcome($ctx, $outcome);
    }

    /** @param array<string, mixed> $adjustment */
    public function adjust(OfficeErpExecutionContext $ctx, OfficeErpInvoice $invoice, array $adjustment, string $key): OfficeErpInvoiceAdjustment
    {
        abort_unless((int) $invoice->account_id === $ctx->accountId, 404);
        $invoiceId = (int) $invoice->getKey();
        $adjustment = $this->adjustmentInput($adjustment);
        $payload = ['command' => 'adjust', 'invoice_id' => $invoiceId, 'adjustment' => $adjustment];

        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'invoice.correct',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload),
            function () use ($ctx, $invoiceId, $adjustment, $key): array {
                $invoice = $this->invoice($ctx, $invoiceId, true)->load(['contract']);
                abort_unless($invoice->document_status === 'issued', 409, 'invoice_not_adjustable');
                $balance = OfficeErpBalances::forInvoice($invoice);
                $adjustedCharge = $adjustment['direction'] === 'credit'
                    ? OfficeErpMoney::subtract($balance['adjusted_charge'], $adjustment['amount'])
                    : OfficeErpMoney::add($balance['adjusted_charge'], $adjustment['amount']);
                if (OfficeErpMoney::compare($adjustedCharge, $balance['effective_received']) < 0) {
                    throw ValidationException::withMessages(['amount' => ['O crédito não pode exceder o valor ainda não recebido.']]);
                }

                $correctionOf = null;
                if ($adjustment['corrects_adjustment_id'] !== null) {
                    $correctionOf = OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)
                        ->where('invoice_id', $invoiceId)
                        ->whereKey($adjustment['corrects_adjustment_id'])
                        ->firstOrFail();
                    abort_unless($correctionOf->direction !== $adjustment['direction'], 422, 'correction_direction_must_be_opposite');
                    if (OfficeErpMoney::canonical((string) $correctionOf->getRawOriginal('amount'), 'amount') !== $adjustment['amount']) {
                        throw ValidationException::withMessages(['amount' => ['A correção oposta deve reverter integralmente o ajuste referenciado.']]);
                    }
                }

                $operationId = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
                    ->where('actor_kind', $ctx->actorKind)
                    ->where('actor_identity', $ctx->actorIdentity)
                    ->where('idempotency_key', $key)
                    ->value('id');
                abort_unless($operationId !== null, 409, 'operation_record_missing');
                $record = OfficeErpRecords::persist($ctx, new OfficeErpInvoiceAdjustment([
                    'account_id' => $ctx->accountId,
                    'invoice_id' => $invoiceId,
                    'direction' => $adjustment['direction'],
                    'amount' => $adjustment['amount'],
                    'reason' => $adjustment['reason'],
                    'correction_of_id' => $correctionOf?->id,
                    'operation_id' => $operationId,
                    'posted_by_user_id' => $ctx->actor?->id,
                    'posted_at' => now(),
                ]));
                $contract = $this->contract($ctx, (int) $invoice->contract_id);
                $this->delinquency->reconcile($ctx, $contract, CarbonImmutable::now());

                return ['data' => $this->adjustmentData($record)];
            },
        );

        return $this->adjustmentFromOutcome($ctx, $outcome);
    }

    public function cancel(OfficeErpExecutionContext $ctx, OfficeErpInvoice $invoice, string $reason, string $key): void
    {
        abort_unless((int) $invoice->account_id === $ctx->accountId, 404);
        $invoiceId = (int) $invoice->getKey();
        $reason = $this->reason($reason);
        $payload = ['command' => 'cancel', 'invoice_id' => $invoiceId, 'reason' => $reason];

        $this->operations->execute(
            $ctx,
            $key,
            'invoice.correct',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload),
            function () use ($ctx, $invoiceId): array {
                $invoice = $this->invoice($ctx, $invoiceId, true)->load('contract');
                abort_unless(in_array($invoice->document_status, ['draft', 'issued'], true), 409, 'invoice_not_cancelable');
                if ($invoice->document_status === 'issued') {
                    $balance = OfficeErpBalances::forInvoice($invoice);
                    abort_if(OfficeErpMoney::compare($balance['effective_received'], '0.00') > 0, 409, 'receipt_reversal_required');
                }
                $invoice->forceFill(['document_status' => 'canceled']);
                OfficeErpRecords::persist($ctx, $invoice);
                if ($invoice->issued_at !== null) {
                    $this->delinquency->reconcile($ctx, $invoice->contract, CarbonImmutable::now());
                }

                return ['data' => $this->invoiceData($invoice->refresh()->load(['contract', 'adjustments.correctionOf']))];
            },
        );
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{current_page:int,last_page:int,per_page:int,total:int}} */
    public function index(OfficeErpExecutionContext $ctx, array $filters): array
    {
        $this->admission->capabilities($ctx);
        $query = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->with(['contract.client', 'contract.company', 'contractRevision', 'adjustments.correctionOf'])
            ->orderByDesc('id');

        if (isset($filters['document_status'])) {
            $query->where('document_status', $filters['document_status']);
        }
        if (isset($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(function ($query) use ($term): void {
                $query->where('number', 'like', $term)
                    ->orWhereHas('contract.client', fn ($client) => $client->where('name', 'like', $term)->orWhere('trade_name', 'like', $term))
                    ->orWhereHas('contract.company', fn ($company) => $company->where('name', 'like', $term));
            });
        }

        $page = $query->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'data' => array_map(fn (OfficeErpInvoice $invoice): array => $this->invoiceData($invoice), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    public function show(OfficeErpExecutionContext $ctx, int $invoiceId): array
    {
        $invoice = $this->invoice($ctx, $invoiceId)->load(['contract', 'contractRevision', 'adjustments.correctionOf']);
        $contract = $this->contract($ctx, (int) $invoice->contract_id);
        $this->admission->assertCommand($ctx, 'invoice.read', $contract);

        return ['data' => $this->invoiceData($invoice)];
    }

    /** Revalidate current authority and prelock all business targets before journal identity. @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload): void
    {
        abort_unless(in_array($action, ['invoice.issue', 'invoice.correct'], true), 422);
        $command = $payload['command'] ?? null;
        $isIssue = $action === 'invoice.issue';
        abort_unless(is_string($command) && ($isIssue
            ? in_array($command, ['draft', 'update_draft', 'issue', 'issue_period'], true)
            : in_array($command, ['adjust', 'cancel'], true)), 409);
        $expectedKeys = match ($command) {
            'draft' => ['command', 'contract_id', 'input'],
            'update_draft' => ['command', 'invoice_id', 'input'],
            'issue' => ['command', 'invoice_id'],
            'issue_period' => ['command', 'contract_id', 'revision_id', 'period'],
            'adjust' => ['command', 'invoice_id', 'adjustment'],
            'cancel' => ['command', 'invoice_id', 'reason'],
        };
        abort_unless(array_diff(array_keys($payload), $expectedKeys) === []
            && array_diff($expectedKeys, array_keys($payload)) === [], 409);

        if (in_array($command, ['draft', 'issue_period'], true)) {
            $contractId = $this->id($payload['contract_id'] ?? null, 'contract_id');
            $contract = $this->contract($ctx, $contractId);
            $this->admission->assertCommand($ctx, $action, $contract);
            $contract = $this->contract($ctx, $contractId, true);
            $this->lockCompanySubscription($contract);
            if ($command === 'issue_period') {
                $revisionId = $this->id($payload['revision_id'] ?? null, 'revision_id');
                $this->revision($ctx, $contract, $revisionId);
                $period = $this->periodInput($payload['period'] ?? []);
                $existing = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
                    ->where('contract_id', $contractId)
                    ->where('kind', 'recurring')
                    ->whereDate('period_start', $period['start'])
                    ->whereDate('period_end', $period['end'])
                    ->first();
                $this->lockCounter($ctx, $this->numberYear($contract, $existing));
                $this->lockInvoiceAggregate($ctx, $contractId);
                OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
                    ->where('contract_id', $contractId)
                    ->where('kind', 'recurring')
                    ->whereDate('period_start', $period['start'])
                    ->whereDate('period_end', $period['end'])
                    ->lockForUpdate()
                    ->first();
            } else {
                $this->manualInput($payload['input'] ?? []);
            }

            return;
        }

        $invoiceId = $this->id($payload['invoice_id'] ?? null, 'invoice_id');
        $invoice = $this->invoice($ctx, $invoiceId);
        $contract = $this->contract($ctx, (int) $invoice->contract_id);
        $this->admission->assertCommand($ctx, $action, $contract);
        $contract = $this->contract($ctx, (int) $invoice->contract_id, true);
        $this->lockCompanySubscription($contract);

        if ($command === 'issue') {
            abort_unless($invoice->kind === 'manual', 409);
            $this->lockCounter($ctx, $this->numberYear($contract, $invoice));
            $this->lockInvoiceAggregate($ctx, (int) $contract->id);
            $this->invoice($ctx, $invoiceId, true);

            return;
        }

        $this->lockInvoiceAggregate($ctx, (int) $contract->id);
        $invoice = $this->invoice($ctx, $invoiceId, true);
        if ($command === 'update_draft') {
            abort_unless($invoice->kind === 'manual', 409);
            $this->manualInput($payload['input'] ?? []);

            return;
        }
        if ($command === 'adjust') {
            $adjustment = $this->adjustmentInput($payload['adjustment'] ?? []);
            if ($adjustment['corrects_adjustment_id'] !== null) {
                $correctionOf = OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)
                    ->where('invoice_id', $invoiceId)
                    ->whereKey($adjustment['corrects_adjustment_id'])
                    ->firstOrFail();
                if ($correctionOf->direction === $adjustment['direction']) {
                    throw ValidationException::withMessages(['corrects_adjustment_id' => ['A correção deve usar a direção oposta.']]);
                }
                if (OfficeErpMoney::canonical((string) $correctionOf->getRawOriginal('amount'), 'amount') !== $adjustment['amount']) {
                    throw ValidationException::withMessages(['amount' => ['A correção oposta deve reverter integralmente o ajuste referenciado.']]);
                }
            }

            return;
        }
        if ($command === 'cancel') {
            $this->reason($payload['reason'] ?? null);
        }
    }

    private function lockCompanySubscription(OfficeErpContract $contract): void
    {
        if ($contract->erp_company_id === null) {
            return;
        }

        ErpSubscription::query()
            ->where('erp_company_id', $contract->erp_company_id)
            ->where('office_erp_contract_id', $contract->id)
            ->lockForUpdate()
            ->first();
    }

    private function lockCounter(OfficeErpExecutionContext $ctx, int $year): void
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpInvoiceCounter::class)->where('year', $year);
        if ($query->lockForUpdate()->first() !== null) {
            return;
        }

        try {
            DB::transaction(fn () => OfficeErpRecords::persist($ctx, new OfficeErpInvoiceCounter([
                'account_id' => $ctx->accountId,
                'year' => $year,
                'next_number' => 1,
            ])));
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (! in_array($state, ['23000', '23505'], true)
                || ! str_contains(strtolower($exception->getMessage()), 'office_erp_invoice_counters')) {
                throw $exception;
            }
        }

        $query->lockForUpdate()->firstOrFail();
    }

    private function lockInvoiceAggregate(OfficeErpExecutionContext $ctx, int $contractId): void
    {
        $invoiceIds = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->where('contract_id', $contractId)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
        if ($invoiceIds->isEmpty()) {
            return;
        }

        OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('invoice_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        $receiptIds = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('receipt_id')
            ->distinct()
            ->pluck('receipt_id');
        if ($receiptIds->isNotEmpty()) {
            OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
                ->whereIn('id', $receiptIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            OfficeErpRecords::query($ctx, OfficeErpReceiptReversal::class)
                ->whereIn('receipt_id', $receiptIds)
                ->orderBy('receipt_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
        OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function contract(OfficeErpExecutionContext $ctx, int $id, bool $lock = false): OfficeErpContract
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function invoice(OfficeErpExecutionContext $ctx, int $id, bool $lock = false): OfficeErpInvoice
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function currentRevision(OfficeErpExecutionContext $ctx, OfficeErpContract $contract): OfficeErpContractRevision
    {
        return $this->revision($ctx, $contract, (int) $contract->current_revision_id);
    }

    private function revision(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, int $revisionId): OfficeErpContractRevision
    {
        return OfficeErpRecords::query($ctx, OfficeErpContractRevision::class)
            ->where('contract_id', $contract->id)
            ->whereKey($revisionId)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $input @return array{items:list<array{description:string,amount:string,discount:string}>,period_start:string,period_end:string,due_on:string,original_total:string} */
    private function manualInput(array $input): array
    {
        $required = ['items', 'period_start', 'period_end', 'due_on'];
        $allowed = [...$required, 'original_total'];
        if (array_diff(array_keys($input), $allowed) !== [] || array_diff($required, array_keys($input)) !== []) {
            throw ValidationException::withMessages(['invoice' => ['Os dados da fatura estão incompletos ou contêm campos não permitidos.']]);
        }
        if (! is_array($input['items']) || ! array_is_list($input['items']) || count($input['items']) < 1 || count($input['items']) > 100) {
            throw ValidationException::withMessages(['items' => ['Informe de uma a cem linhas.']]);
        }

        $items = [];
        $total = '0.00';
        foreach ($input['items'] as $index => $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['description', 'amount', 'discount']) !== []
                || array_diff(['description', 'amount', 'discount'], array_keys($item)) !== []) {
                throw ValidationException::withMessages(['items.'.$index => ['A linha contém campos inválidos.']]);
            }
            $description = is_string($item['description']) ? trim($item['description']) : '';
            if ($description === '' || mb_strlen($description) > 1000) {
                throw ValidationException::withMessages(['items.'.$index.'.description' => ['Informe uma descrição de até mil caracteres.']]);
            }
            $amount = OfficeErpMoney::canonical($item['amount'], 'items.'.$index.'.amount');
            $discount = OfficeErpMoney::canonical($item['discount'], 'items.'.$index.'.discount');
            if (OfficeErpMoney::compare($discount, $amount) > 0) {
                throw ValidationException::withMessages(['items.'.$index.'.discount' => ['O desconto não pode superar o valor da linha.']]);
            }
            $items[] = ['description' => $description, 'amount' => $amount, 'discount' => $discount];
            $total = OfficeErpMoney::add($total, OfficeErpMoney::subtract($amount, $discount));
        }

        $periodStart = $this->date($input['period_start'], 'period_start');
        $periodEnd = $this->date($input['period_end'], 'period_end');
        $dueOn = $this->date($input['due_on'], 'due_on');
        if ($periodStart >= $periodEnd) {
            throw ValidationException::withMessages(['period_end' => ['A data final deve ser posterior ao início do período.']]);
        }
        if (array_key_exists('original_total', $input)
            && OfficeErpMoney::canonical($input['original_total'], 'original_total') !== $total) {
            throw ValidationException::withMessages(['original_total' => ['O total não corresponde às linhas da fatura.']]);
        }

        return [
            'items' => $items,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_on' => $dueOn,
            'original_total' => $total,
        ];
    }

    /** @param array<string, mixed> $input @return array{start:string,end:string,due_on:string} */
    private function periodInput(array $input): array
    {
        if (array_diff(array_keys($input), ['start', 'end', 'due_on']) !== []
            || array_diff(['start', 'end', 'due_on'], array_keys($input)) !== []) {
            throw ValidationException::withMessages(['period' => ['O período está incompleto ou contém campos inválidos.']]);
        }
        $start = $this->date($input['start'], 'period.start');
        $end = $this->date($input['end'], 'period.end');
        $dueOn = $this->date($input['due_on'], 'period.due_on');
        if ($start >= $end) {
            throw ValidationException::withMessages(['period.end' => ['A data final deve ser posterior ao início do período.']]);
        }

        return ['start' => $start, 'end' => $end, 'due_on' => $dueOn];
    }

    /** @param array<string, mixed> $input @return array{direction:string,amount:string,reason:string,corrects_adjustment_id:?int} */
    private function adjustmentInput(array $input): array
    {
        if (array_diff(array_keys($input), ['direction', 'amount', 'reason', 'corrects_adjustment_id']) !== []
            || array_diff(['direction', 'amount', 'reason'], array_keys($input)) !== []) {
            throw ValidationException::withMessages(['adjustment' => ['Os dados do ajuste estão incompletos ou contêm campos inválidos.']]);
        }
        $direction = $input['direction'];
        if (! is_string($direction) || ! in_array($direction, ['debit', 'credit'], true)) {
            throw ValidationException::withMessages(['direction' => ['Selecione débito ou crédito.']]);
        }
        $amount = OfficeErpMoney::canonical($input['amount'], 'amount');
        if (OfficeErpMoney::compare($amount, '0.00') <= 0) {
            throw ValidationException::withMessages(['amount' => ['O valor do ajuste deve ser positivo.']]);
        }
        $reason = $this->reason($input['reason']);
        $correctionId = array_key_exists('corrects_adjustment_id', $input) && $input['corrects_adjustment_id'] !== null
            ? $this->id($input['corrects_adjustment_id'], 'corrects_adjustment_id')
            : null;

        return ['direction' => $direction, 'amount' => $amount, 'reason' => $reason, 'corrects_adjustment_id' => $correctionId];
    }

    private function reason(mixed $reason): string
    {
        if (! is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > 2000) {
            throw ValidationException::withMessages(['reason' => ['Informe um motivo de até dois mil caracteres.']]);
        }

        return trim($reason);
    }

    private function date(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => ['Informe uma data válida no formato AAAA-MM-DD.']]);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ValidationException::withMessages([$field => ['Informe uma data válida no formato AAAA-MM-DD.']]);
        }

        return $value;
    }

    private function id(mixed $value, string $field): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9]\d{0,15}$/D', (string) $value)
            || bccomp((string) $value, '9007199254740991', 0) > 0) {
            throw ValidationException::withMessages([$field => ['Identificador inválido.']]);
        }

        return (int) $value;
    }

    private function numberYear(OfficeErpContract $contract, ?OfficeErpInvoice $invoice): int
    {
        return $invoice?->issued_at?->setTimezone($contract->billing_timezone)->year
            ?? CarbonImmutable::now($contract->billing_timezone)->year;
    }

    /** @return array<string, mixed> */
    private function sellerSnapshot(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, OfficeErpContractRevision $revision): array
    {
        $account = Account::query()->whereKey($ctx->accountId)->firstOrFail();

        return [
            'account' => ['id' => (int) $account->id, 'name' => (string) $account->name],
            'contract_terms' => [
                'contract_id' => (int) $contract->id,
                'revision_id' => (int) $revision->id,
                'price' => OfficeErpMoney::canonical((string) $revision->price, 'price'),
                'currency' => (string) $revision->currency,
                'interval_months' => (int) $revision->interval_months,
                'due_in_days' => (int) $revision->due_in_days,
                'module_keys' => array_values($revision->module_keys ?? []),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function billToSnapshot(OfficeErpExecutionContext $ctx, OfficeErpContract $contract): array
    {
        $client = $ctx->run(fn (): Client => Client::query()
            ->where('account_id', $ctx->accountId)
            ->whereKey($contract->client_id)
            ->firstOrFail());
        $clientSnapshot = [];
        foreach (['id', 'person_type', 'tax_id', 'name', 'trade_name', 'street_type', 'street', 'address_number', 'address_complement', 'district', 'postal_code', 'city', 'state', 'email', 'phone'] as $field) {
            $clientSnapshot[$field] = $field === 'id' ? (int) $client->getKey() : $client->getAttribute($field);
        }
        $companySnapshot = null;
        if ($contract->erp_company_id !== null) {
            $company = ErpCompany::query()->whereKey($contract->erp_company_id)->firstOrFail();
            $companySnapshot = [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
                'cnpj' => $company->cnpj,
            ];
        }

        return ['client' => $clientSnapshot, 'company' => $companySnapshot];
    }

    /** @return array<string, mixed> */
    private function invoiceData(OfficeErpInvoice $invoice): array
    {
        $invoice->loadMissing(['contract', 'contractRevision', 'adjustments.correctionOf']);
        $balance = OfficeErpBalances::forInvoice($invoice);

        return [
            'id' => (int) $invoice->id,
            'contract_id' => (int) $invoice->contract_id,
            'contract_revision_id' => (int) $invoice->contract_revision_id,
            'kind' => (string) $invoice->kind,
            'document_status' => (string) $invoice->document_status,
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at?->toISOString(),
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'due_on' => $invoice->due_on->toDateString(),
            'currency' => (string) $invoice->currency,
            'seller_snapshot' => $invoice->seller_snapshot,
            'bill_to_snapshot' => $invoice->bill_to_snapshot,
            'items_snapshot' => $invoice->items_snapshot,
            'original_total' => OfficeErpMoney::canonical((string) $invoice->getRawOriginal('original_total'), 'original_total'),
            'adjustments' => $invoice->adjustments->map(fn (OfficeErpInvoiceAdjustment $adjustment): array => $this->adjustmentData($adjustment))->values()->all(),
            'balance' => $balance,
        ];
    }

    /** @return array<string, mixed> */
    private function adjustmentData(OfficeErpInvoiceAdjustment $adjustment): array
    {
        return [
            'id' => (int) $adjustment->id,
            'direction' => (string) $adjustment->direction,
            'amount' => OfficeErpMoney::canonical((string) $adjustment->getRawOriginal('amount'), 'amount'),
            'reason' => (string) $adjustment->reason,
            'corrects_adjustment_id' => $adjustment->correction_of_id === null ? null : (int) $adjustment->correction_of_id,
            'posted_at' => $adjustment->posted_at?->toISOString(),
        ];
    }

    private function invoiceFromOutcome(OfficeErpExecutionContext $ctx, array $outcome): OfficeErpInvoice
    {
        $id = $this->id(data_get($outcome, 'data.id'), 'invoice_id');

        $invoice = $this->invoice($ctx, $id)->load(['contract', 'contractRevision', 'adjustments.correctionOf']);
        $invoice->setAttribute('_office_erp_command_outcome', data_get($outcome, 'data'));

        return $invoice;
    }

    /** @return array<string, mixed> */
    public function commandData(OfficeErpInvoice $invoice): array
    {
        $outcome = $invoice->getAttribute('_office_erp_command_outcome');

        return is_array($outcome) ? $outcome : $this->invoiceData($invoice);
    }

    private function adjustmentFromOutcome(OfficeErpExecutionContext $ctx, array $outcome): OfficeErpInvoiceAdjustment
    {
        $id = $this->id(data_get($outcome, 'data.id'), 'adjustment_id');

        return OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)->whereKey($id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function manualInputFromInvoice(OfficeErpInvoice $invoice): array
    {
        return [
            'items' => $invoice->items_snapshot,
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'due_on' => $invoice->due_on->toDateString(),
        ];
    }
}
