<?php

namespace App\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptProof;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OfficeErpReceipts
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
        private readonly OfficeErpDelinquency $delinquency,
        private readonly OfficeErpReceiptProofs $proofs,
    ) {}

    /** @param array<string, mixed> $receipt */
    public function post(OfficeErpExecutionContext $ctx, array $receipt, string $key): OfficeErpReceipt
    {
        $input = $this->postInput($receipt);
        $payload = ['command' => 'post', 'input' => $input];
        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'receipt.record',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload, $key),
            fn (): array => $this->postEffect($ctx, $input, $key),
        );

        return $this->receiptFromOutcome($ctx, $outcome);
    }

    public function reverse(OfficeErpExecutionContext $ctx, OfficeErpReceipt $receipt, string $reason, string $key): OfficeErpReceiptReversal
    {
        abort_unless((int) $receipt->account_id === $ctx->accountId, 404);
        $receiptId = (int) $receipt->getKey();
        $reason = $this->reason($reason);
        $payload = ['command' => 'reverse', 'receipt_id' => $receiptId, 'reason' => $reason];
        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'receipt.reverse',
            $payload,
            fn (string $action, array $currentPayload): mixed => $this->authorizeOperation($ctx, $action, $currentPayload, $key),
            fn (): array => $this->reverseEffect($ctx, $receiptId, $reason, $key),
        );

        return $this->reversalFromOutcome($ctx, $outcome);
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{current_page:int,last_page:int,per_page:int,total:int}} */
    public function index(OfficeErpExecutionContext $ctx, array $filters = []): array
    {
        $this->admission->capabilities($ctx);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = in_array((int) ($filters['per_page'] ?? 25), [25, 50, 100], true)
            ? (int) ($filters['per_page'] ?? 25)
            : 25;
        $query = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
            ->with(['allocations.invoice.contract', 'reversal'])
            ->orderByDesc('id');
        if (isset($filters['received_from'])) {
            $query->whereDate('received_on', '>=', $filters['received_from']);
        }
        if (isset($filters['received_to'])) {
            $query->whereDate('received_on', '<=', $filters['received_to']);
        }

        $receipts = $query->paginate($perPage, ['*'], 'page', $page);
        $data = [];
        foreach ($receipts->items() as $receipt) {
            $this->authorizeReceiptRead($ctx, $receipt);
            $data[] = $this->receiptData($ctx, $receipt);
        }

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
        ];
    }

    /** @return array{data:array<string, mixed>} */
    public function show(OfficeErpExecutionContext $ctx, int $receiptId): array
    {
        $receipt = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
            ->with(['allocations.invoice.contract', 'reversal'])
            ->whereKey($receiptId)
            ->firstOrFail();
        $this->authorizeReceiptRead($ctx, $receipt);

        return ['data' => $this->receiptData($ctx, $receipt)];
    }

    /** @return array<string, mixed> The immutable durable outcome stored by the operation journal. */
    public function commandOutcome(OfficeErpExecutionContext $ctx, int $operationId): array
    {
        $operation = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)->whereKey($operationId)->firstOrFail();
        $outcome = $operation->outcome;
        abort_unless(is_array($outcome) && isset($outcome['data']) && is_array($outcome['data']), 409);

        return $outcome;
    }

    /** Revalidate authority and lock all affected aggregates before operation identity. @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, ?string $key = null): void
    {
        $command = $payload['command'] ?? null;
        if ($command === 'post' && $action === 'receipt.record') {
            abort_unless(array_diff(array_keys($payload), ['command', 'input']) === []
                && array_diff(['command', 'input'], array_keys($payload)) === [], 409);
            $input = $this->postInput($payload['input'] ?? []);
            $this->lockReceiptInvoices($ctx, array_column($input['allocations'], 'invoice_id'), 'receipt.record');
            if ($input['proof_id'] !== null) {
                $proof = OfficeErpRecords::query($ctx, OfficeErpReceiptProof::class)
                    ->whereKey($input['proof_id'])
                    ->lockForUpdate()
                    ->first();
                if ($proof === null || (int) $proof->actor_user_id !== (int) $ctx->actor?->getKey()) {
                    throw ValidationException::withMessages(['proof_id' => ['A evidência não está disponível para este operador.']]);
                }
                if ($proof->receipt_id === null && ($proof->expires_at === null || ! $proof->expires_at->isFuture())) {
                    throw ValidationException::withMessages(['proof_id' => ['A evidência temporária expirou. Envie o arquivo novamente.']]);
                }
            }

            return;
        }

        if ($command === 'reverse' && $action === 'receipt.reverse') {
            abort_unless(array_diff(array_keys($payload), ['command', 'receipt_id', 'reason']) === []
                && array_diff(['command', 'receipt_id', 'reason'], array_keys($payload)) === [], 409);
            $receiptId = $this->id($payload['receipt_id'] ?? null, 'receipt_id');
            $this->reason($payload['reason'] ?? null);
            $receipt = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)->whereKey($receiptId)->firstOrFail();
            $invoiceIds = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
                ->where('receipt_id', $receiptId)
                ->orderBy('invoice_id')
                ->pluck('invoice_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            abort_if($invoiceIds === [], 404);
            $this->lockReceiptInvoices($ctx, $invoiceIds, 'receipt.reverse');
            $lockedReceipt = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $lockedReceipt->account_id === $ctx->accountId, 404);

            return;
        }

        abort(409, 'receipt_operation_mismatch');
    }

    /** @param list<int> $requestedInvoiceIds */
    private function lockReceiptInvoices(OfficeErpExecutionContext $ctx, array $requestedInvoiceIds, string $action): void
    {
        $this->admission->lockCommercialAggregate($ctx);
        $this->admission->releaseExpiredReservations($ctx);
        $requestedInvoiceIds = array_values(array_unique(array_map('intval', $requestedInvoiceIds)));
        sort($requestedInvoiceIds, SORT_NUMERIC);
        abort_if($requestedInvoiceIds === [], 404);

        $targetInvoices = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->whereIn('id', $requestedInvoiceIds)
            ->orderBy('id')
            ->get(['id', 'contract_id']);
        if ($targetInvoices->count() !== count($requestedInvoiceIds)) {
            abort(404);
        }
        $contractIds = $targetInvoices->pluck('contract_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $contracts = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereIn('id', $contractIds)
            ->orderBy('id')
            ->get();
        if ($contracts->count() !== count($contractIds)) {
            abort(404);
        }

        $companyIds = $contracts->pluck('erp_company_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        if ($companyIds !== []) {
            $companies = ErpCompany::query()->whereIn('id', $companyIds)->orderBy('id')->lockForUpdate()->get(['id']);
            abort_unless($companies->count() === count($companyIds), 404);
            ErpSubscription::query()
                ->whereIn('erp_company_id', $companyIds)
                ->whereIn('office_erp_contract_id', $contractIds)
                ->orderBy('erp_company_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }

        $lockedContracts = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereIn('id', $contractIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        abort_unless($lockedContracts->count() === count($contractIds), 404);
        OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
            ->whereIn('contract_id', $contractIds)
            ->orderBy('contract_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        foreach ($lockedContracts as $contract) {
            $this->admission->assertCommand($ctx, $action, $contract);
        }

        OfficeErpRecords::query($ctx, OfficeErpCommercialSetting::class)->lockForUpdate()->first();
        $invoices = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->whereIn('contract_id', $contractIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        $allInvoiceIds = $invoices->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($allInvoiceIds !== []) {
            OfficeErpRecords::query($ctx, OfficeErpInvoiceAdjustment::class)
                ->whereIn('invoice_id', $allInvoiceIds)
                ->orderBy('invoice_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $receiptIds = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
                ->whereIn('invoice_id', $allInvoiceIds)
                ->orderBy('receipt_id')
                ->distinct()
                ->pluck('receipt_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if ($receiptIds !== []) {
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
                ->whereIn('invoice_id', $allInvoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
    }

    /** @param array<string, mixed> $input @return array{amount:string,received_on:string,reference:?string,proof_id:?string,allocations:list<array{invoice_id:int,amount:string}>} */
    private function postInput(array $input): array
    {
        $allowed = ['amount', 'received_on', 'reference', 'proof_id', 'allocations'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['receipt' => ['O comando contém campos inválidos.']]);
        }
        $amount = OfficeErpMoney::canonical($input['amount'] ?? null, 'amount');
        if (OfficeErpMoney::compare($amount, '0.00') <= 0) {
            throw ValidationException::withMessages(['amount' => ['O valor do recebimento deve ser maior que zero.']]);
        }
        $receivedOn = $input['received_on'] ?? null;
        $dateParts = is_string($receivedOn) ? explode('-', $receivedOn) : [];
        if (count($dateParts) !== 3
            || ! ctype_digit($dateParts[0])
            || ! ctype_digit($dateParts[1])
            || ! ctype_digit($dateParts[2])
            || strlen($dateParts[0]) !== 4
            || strlen($dateParts[1]) !== 2
            || strlen($dateParts[2]) !== 2
            || ! checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
            throw ValidationException::withMessages(['received_on' => ['Informe uma data válida no formato AAAA-MM-DD.']]);
        }
        $reference = $input['reference'] ?? null;
        if ($reference !== null && (! is_string($reference) || mb_strlen($reference) > 191)) {
            throw ValidationException::withMessages(['reference' => ['A referência deve ter até 191 caracteres.']]);
        }
        $reference = is_string($reference) ? trim($reference) : null;
        $reference = $reference === '' ? null : $reference;
        $proofId = $input['proof_id'] ?? null;
        if ($proofId !== null && (! is_string($proofId) || ! Str::isUuid($proofId))) {
            throw ValidationException::withMessages(['proof_id' => ['A evidência informada não é válida.']]);
        }
        $allocations = $input['allocations'] ?? null;
        if (! is_array($allocations) || ! array_is_list($allocations) || $allocations === []) {
            throw ValidationException::withMessages(['allocations' => ['Informe ao menos uma alocação.']]);
        }

        $normalizedAllocations = [];
        $sum = '0.00';
        foreach ($allocations as $allocation) {
            if (! is_array($allocation) || array_diff(array_keys($allocation), ['invoice_id', 'amount']) !== []) {
                throw ValidationException::withMessages(['allocations' => ['As alocações informadas não são válidas.']]);
            }
            $invoiceId = $this->id($allocation['invoice_id'] ?? null, 'allocations');
            $allocationAmount = OfficeErpMoney::canonical($allocation['amount'] ?? null, 'allocations');
            if (OfficeErpMoney::compare($allocationAmount, '0.00') <= 0) {
                throw ValidationException::withMessages(['allocations' => ['Cada alocação deve ter valor maior que zero.']]);
            }
            $normalizedAllocations[] = ['invoice_id' => $invoiceId, 'amount' => $allocationAmount];
            $sum = OfficeErpMoney::add($sum, $allocationAmount);
        }

        usort($normalizedAllocations, static fn (array $left, array $right): int => $left['invoice_id'] <=> $right['invoice_id']);
        if (count(array_unique(array_column($normalizedAllocations, 'invoice_id'))) !== count($normalizedAllocations)) {
            throw ValidationException::withMessages(['allocations' => ['Cada fatura pode aparecer uma única vez.']]);
        }
        if (OfficeErpMoney::compare($sum, $amount) !== 0) {
            throw ValidationException::withMessages(['allocations' => ['A soma das alocações deve ser igual ao valor do recebimento.']]);
        }

        return [
            'amount' => $amount,
            'received_on' => $receivedOn,
            'reference' => $reference,
            'proof_id' => $proofId,
            'allocations' => $normalizedAllocations,
        ];
    }

    /** @param array{amount:string,received_on:string,reference:?string,proof_id:?string,allocations:list<array{invoice_id:int,amount:string}>} $input @return array{data:array<string, mixed>} */
    private function postEffect(OfficeErpExecutionContext $ctx, array $input, string $key): array
    {
        $invoiceIds = array_column($input['allocations'], 'invoice_id');
        $invoices = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->whereIn('id', $invoiceIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        abort_unless($invoices->count() === count($invoiceIds), 404);
        foreach ($input['allocations'] as $allocation) {
            $invoice = $invoices->get($allocation['invoice_id']);
            abort_unless($invoice instanceof OfficeErpInvoice && $invoice->document_status === 'issued', 409, 'invoice_not_receivable');
            $balance = OfficeErpBalances::forInvoice($invoice);
            if (OfficeErpMoney::compare($allocation['amount'], $balance['outstanding']) > 0) {
                abort(response()->json([
                    'code' => 'receipt_balance_conflict',
                    'message' => 'O saldo atual da fatura não comporta esta alocação.',
                    'data' => ['current_balances' => $this->currentBalances($ctx, $invoiceIds)],
                ], 409));
            }
        }

        $operationId = $this->operationId($ctx, $key);
        $receipt = OfficeErpRecords::persist($ctx, new OfficeErpReceipt([
            'account_id' => $ctx->accountId,
            'amount' => $input['amount'],
            'received_on' => $input['received_on'],
            'reference' => $input['reference'],
            'proof_path' => null,
            'method' => 'manual',
            'recorded_by_user_id' => $ctx->actor?->getKey(),
            'operation_id' => $operationId,
        ]));
        if ($input['proof_id'] !== null) {
            $this->proofs->attach($ctx, $receipt, $input['proof_id']);
        }
        foreach ($input['allocations'] as $allocation) {
            OfficeErpRecords::persist($ctx, new OfficeErpReceiptAllocation([
                'account_id' => $ctx->accountId,
                'receipt_id' => $receipt->getKey(),
                'invoice_id' => $allocation['invoice_id'],
                'amount' => $allocation['amount'],
                'operation_id' => $operationId,
            ]));
        }
        $this->reconcileContracts($ctx, $invoices);

        return ['data' => $this->receiptData($ctx, $receipt->refresh())];
    }

    /** @return array{data:array<string, mixed>} */
    private function reverseEffect(OfficeErpExecutionContext $ctx, int $receiptId, string $reason, string $key): array
    {
        $receipt = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)->whereKey($receiptId)->firstOrFail();
        $allocations = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->where('receipt_id', $receiptId)
            ->orderBy('invoice_id')
            ->get();
        abort_if($allocations->isEmpty(), 409, 'receipt_has_no_allocations');
        abort_if(OfficeErpRecords::query($ctx, OfficeErpReceiptReversal::class)->where('receipt_id', $receiptId)->exists(), 409, 'receipt_already_reversed');

        $operationId = $this->operationId($ctx, $key);
        $reversal = OfficeErpRecords::persist($ctx, new OfficeErpReceiptReversal([
            'account_id' => $ctx->accountId,
            'receipt_id' => $receiptId,
            'reason' => $reason,
            'reversed_by_user_id' => $ctx->actor?->getKey(),
            'operation_id' => $operationId,
            'reversed_at' => CarbonImmutable::now(),
        ]));
        $invoices = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->whereIn('id', $allocations->pluck('invoice_id'))
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $this->reconcileContracts($ctx, $invoices);

        return ['data' => $this->reversalData($ctx, $receipt, $reversal, $allocations)];
    }

    /** @param Collection<int, OfficeErpInvoice> $invoices */
    private function reconcileContracts(OfficeErpExecutionContext $ctx, Collection $invoices): void
    {
        $contractIds = $invoices->pluck('contract_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values();
        foreach ($contractIds as $contractId) {
            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contractId)->firstOrFail();
            $this->delinquency->reconcile($ctx, $contract, CarbonImmutable::now());
        }
    }

    /** @param array<string, mixed> $outcome */
    private function receiptFromOutcome(OfficeErpExecutionContext $ctx, array $outcome): OfficeErpReceipt
    {
        $receiptId = $outcome['data']['id'] ?? null;
        abort_unless(is_int($receiptId), 409);

        return OfficeErpRecords::query($ctx, OfficeErpReceipt::class)->whereKey($receiptId)->firstOrFail();
    }

    /** @param array<string, mixed> $outcome */
    private function reversalFromOutcome(OfficeErpExecutionContext $ctx, array $outcome): OfficeErpReceiptReversal
    {
        $reversalId = $outcome['data']['id'] ?? null;
        abort_unless(is_int($reversalId), 409);

        return OfficeErpRecords::query($ctx, OfficeErpReceiptReversal::class)->whereKey($reversalId)->firstOrFail();
    }

    private function operationId(OfficeErpExecutionContext $ctx, string $key): int
    {
        $id = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)
            ->value('id');
        abort_unless($id !== null, 409, 'operation_record_missing');

        return (int) $id;
    }

    /** @return list<array{invoice_id:int,balance:array<string, mixed>}> */
    private function currentBalances(OfficeErpExecutionContext $ctx, array $invoiceIds): array
    {
        $invoices = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->whereIn('id', $invoiceIds)
            ->orderBy('id')
            ->get();
        $balances = [];
        foreach ($invoices as $invoice) {
            $balances[] = ['invoice_id' => (int) $invoice->id, 'balance' => OfficeErpBalances::forInvoice($invoice)];
        }

        return $balances;
    }

    /** @return array<string, mixed> */
    private function receiptData(OfficeErpExecutionContext $ctx, OfficeErpReceipt $receipt): array
    {
        $allocations = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->where('receipt_id', $receipt->getKey())
            ->orderBy('invoice_id')
            ->get();
        $invoiceIds = $allocations->pluck('invoice_id')->map(static fn ($id): int => (int) $id)->all();
        $proof = OfficeErpRecords::query($ctx, OfficeErpReceiptProof::class)
            ->where('receipt_id', $receipt->getKey())
            ->first(['id']);
        $reversal = OfficeErpRecords::query($ctx, OfficeErpReceiptReversal::class)
            ->where('receipt_id', $receipt->getKey())
            ->first();

        return [
            'id' => (int) $receipt->id,
            'amount' => OfficeErpMoney::canonical((string) $receipt->getRawOriginal('amount')),
            'received_on' => $receipt->received_on?->toDateString(),
            'reference' => $receipt->reference,
            'method' => (string) $receipt->method,
            'recorded_by_user_id' => $receipt->recorded_by_user_id === null ? null : (int) $receipt->recorded_by_user_id,
            'proof_id' => $proof?->getKey(),
            'allocations' => $allocations->map(static fn (OfficeErpReceiptAllocation $allocation): array => [
                'invoice_id' => (int) $allocation->invoice_id,
                'amount' => OfficeErpMoney::canonical((string) $allocation->getRawOriginal('amount')),
            ])->values()->all(),
            'current_balances' => $this->currentBalances($ctx, $invoiceIds),
            'reversal' => $reversal === null ? null : $this->reversalSummary($reversal),
        ];
    }

    /** @param Collection<int, OfficeErpReceiptAllocation> $allocations @return array<string, mixed> */
    private function reversalData(OfficeErpExecutionContext $ctx, OfficeErpReceipt $receipt, OfficeErpReceiptReversal $reversal, Collection $allocations): array
    {
        $invoiceIds = $allocations->pluck('invoice_id')->map(static fn ($id): int => (int) $id)->all();

        return [
            'id' => (int) $reversal->id,
            'receipt_id' => (int) $receipt->id,
            'reason' => (string) $reversal->reason,
            'reversed_by_user_id' => $reversal->reversed_by_user_id === null ? null : (int) $reversal->reversed_by_user_id,
            'reversed_at' => $reversal->reversed_at?->toISOString(),
            'current_balances' => $this->currentBalances($ctx, $invoiceIds),
        ];
    }

    /** @return array<string, mixed> */
    private function reversalSummary(OfficeErpReceiptReversal $reversal): array
    {
        return [
            'id' => (int) $reversal->id,
            'reason' => (string) $reversal->reason,
            'reversed_by_user_id' => $reversal->reversed_by_user_id === null ? null : (int) $reversal->reversed_by_user_id,
            'reversed_at' => $reversal->reversed_at?->toISOString(),
        ];
    }

    private function authorizeReceiptRead(OfficeErpExecutionContext $ctx, OfficeErpReceipt $receipt): void
    {
        $contractIds = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->where('receipt_id', $receipt->getKey())
            ->join('office_erp_invoices', function ($join): void {
                $join->on('office_erp_receipt_allocations.account_id', '=', 'office_erp_invoices.account_id')
                    ->on('office_erp_receipt_allocations.invoice_id', '=', 'office_erp_invoices.id');
            })
            ->orderBy('office_erp_invoices.contract_id')
            ->distinct()
            ->pluck('office_erp_invoices.contract_id');
        abort_if($contractIds->isEmpty(), 404);
        foreach ($contractIds as $contractId) {
            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contractId)->firstOrFail();
            $this->admission->assertCommand($ctx, 'receipt.read', $contract);
        }
    }

    private function id(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1 && strlen($value) <= 15) {
            return (int) $value;
        }

        throw ValidationException::withMessages([$field => ['Informe um identificador válido.']]);
    }

    private function reason(mixed $reason): string
    {
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => ['Informe o motivo do estorno com até 2.000 caracteres.']]);
        }

        return trim($reason);
    }
}
