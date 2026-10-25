<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpReceipts;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptProof;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OfficeErpCommercialConcurrencyTest extends OfficeErpCommercialProcessTestCase
{
    public function test_last_slot_reservation_is_won_by_exactly_one_independent_worker(): void
    {
        $fixture = $this->commercialFixture(1);
        [$firstContractId, $secondContractId] = $fixture['contract_ids'];

        $first = $this->worker('reserve', $this->reservationPayload($fixture, $firstContractId, true));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertSame($this->signalData($first, 'ready')['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('for update', strtolower($held['lock_query']));
        $second = $this->worker('reserve', $this->reservationPayload($fixture, $secondContractId));
        $this->assertWaitingOnAccountLock($second);

        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(409, $secondResult['status']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);
        $allocations = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpLicenceAllocation::class)
                ->whereIn('contract_id', [$firstContractId, $secondContractId])
                ->orderBy('id')
                ->get(),
        ));
        self::assertCount(1, $allocations);
        $allocation = $allocations->sole();
        self::assertSame($firstContractId, (int) $allocation->contract_id);
        self::assertSame((int) $allocation->id, $firstResult['allocation_id']);
        self::assertSame('reserved', $allocation->state);
        self::assertTrue($allocation->expires_at->isFuture());
        self::assertSame(0, OfficeErpLicenceAllocation::query()
            ->whereIn('contract_id', [$firstContractId, $secondContractId])
            ->where('account_id', '!=', $fixture['account_id'])
            ->count());

        $usage = $this->officeAdmin($fixture, fn (): array => $fixture['context']->run(
            fn (): array => app(OfficeErpAdmission::class)->usage($fixture['context']),
        ));
        self::assertSame(['reserved' => 1, 'occupied' => 0], $usage);
        $secondContract = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpContract::class)
                ->whereKey($secondContractId)
                ->firstOrFail()
                ->getAttributes(),
        ));
        self::assertSame($fixture['second_contract_snapshot'], $secondContract);
    }

    public function test_same_period_workers_return_the_same_invoice_and_reserve_one_number(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $contract = $fixture['contracts'][0];
        $period = $fixture['invoice_period'];
        self::assertSame(0, OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)->count());
        self::assertNull(OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year'])->value('next_number'));
        $first = $this->worker('issue_period', $this->invoicePayload($fixture, $contract, 'race-period-first', true));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertSame($this->signalData($first, 'ready')['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('from "accounts"', strtolower($held['lock_query']));
        $second = $this->worker('issue_period', $this->invoicePayload($fixture, $contract, 'race-period-second'));
        $this->assertWaitingOnAccountLock($second);

        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(201, $secondResult['status']);
        self::assertSame($firstResult['invoice_id'], $secondResult['invoice_id']);
        self::assertSame($firstResult['number'], $secondResult['number']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);

        $invoices = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)
                ->where('contract_id', $contract->id)
                ->where('kind', 'recurring')
                ->whereDate('period_start', $period['start'])
                ->whereDate('period_end', $period['end'])
                ->get(),
        ));
        self::assertCount(1, $invoices);
        self::assertSame($firstResult['invoice_id'], (int) $invoices->sole()->id);

        $operations = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpCommercialOperation::class)
                ->whereIn('idempotency_key', ['race-period-first', 'race-period-second'])
                ->orderBy('id')
                ->get(),
        ));
        self::assertCount(2, $operations);
        self::assertNotSame((int) $operations[0]->id, (int) $operations[1]->id);
        self::assertEqualsCanonicalizing(
            [$firstResult['operation_id'], $secondResult['operation_id']],
            $operations->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
        foreach ($operations as $operation) {
            self::assertSame('invoice.issue', $operation->action);
            self::assertSame($firstResult['invoice_id'], (int) data_get($operation->outcome, 'data.id'));
        }
        self::assertSame($firstResult['invoice_id'], (int) data_get($firstResult['operation_outcome'], 'data.id'));
        self::assertSame($firstResult['invoice_id'], (int) data_get($secondResult['operation_outcome'], 'data.id'));

        $audits = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpAuditEvent::class)
                ->whereIn('operation_id', $operations->pluck('id'))
                ->get(),
        ));
        self::assertCount(2, $audits);
        self::assertEqualsCanonicalizing(
            $operations->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            $audits->pluck('operation_id')->map(static fn ($id): int => (int) $id)->all(),
        );
        foreach ($audits as $audit) {
            self::assertSame($firstResult['invoice_id'], (int) data_get($audit->metadata, 'outcome.data.id'));
        }
        $counter = OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year']);
        self::assertSame(1, (clone $counter)->count());
        self::assertSame(2, $counter->value('next_number'));
    }

    public function test_different_contracts_reserve_distinct_sequential_numbers_under_contention(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        [$firstContract, $secondContract] = $fixture['contracts'];
        $period = $fixture['invoice_period'];
        self::assertSame('effective', $firstContract->agreement_status);
        self::assertSame('effective', $secondContract->agreement_status);
        self::assertSame($period['start'], $firstContract->service_anchor_on->toDateString());
        self::assertSame($period['start'], $secondContract->service_anchor_on->toDateString());
        self::assertSame(0, OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)->count());
        self::assertNull(OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year'])->value('next_number'));
        $first = $this->worker('issue_period', $this->invoicePayload($fixture, $firstContract, 'race-number-first', true));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertStringContainsString('from "accounts"', strtolower($held['lock_query']));
        $second = $this->worker('issue_period', $this->invoicePayload($fixture, $secondContract, 'race-number-second'));
        $this->assertWaitingOnAccountLock($second);

        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(201, $secondResult['status']);
        self::assertNotSame($firstResult['invoice_id'], $secondResult['invoice_id']);
        self::assertNotSame($firstResult['number'], $secondResult['number']);
        self::assertSame('ERP-'.$fixture['invoice_year'].'-000001', $firstResult['number']);
        self::assertSame('ERP-'.$fixture['invoice_year'].'-000002', $secondResult['number']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);

        $invoices = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)
                ->whereIn('contract_id', [$firstContract->id, $secondContract->id])
                ->where('kind', 'recurring')
                ->whereDate('period_start', $period['start'])
                ->whereDate('period_end', $period['end'])
                ->orderBy('number')
                ->get(),
        ));
        self::assertCount(2, $invoices);
        self::assertSame([$firstContract->id, $secondContract->id], $invoices->pluck('contract_id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame([$firstResult['number'], $secondResult['number']], $invoices->pluck('number')->all());
        $counter = OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year']);
        self::assertSame(1, (clone $counter)->count());
        self::assertSame(3, $counter->value('next_number'));
    }

    public function test_credit_and_receipt_contention_keeps_invoice_balance_nonnegative(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $first = $this->worker('adjust', $this->financialPayload($fixture, [
            'invoice_id' => (int) $invoice->id,
            'adjustment' => ['direction' => 'credit', 'amount' => '70.00', 'reason' => 'Race credit'],
            'idempotency_key' => 'race-credit-first',
            'hold_invoice_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_invoices');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '40.00'),
            'idempotency_key' => 'race-credit-receipt-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(409, $secondResult['status']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);
        $this->assertInvoiceLedger($fixture, (int) $invoice->id, '30.00', '0.00', '30.00');
        self::assertSame(1, OfficeErpInvoiceAdjustment::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(0, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->whereIn('idempotency_key', ['race-credit-first', 'race-credit-receipt-second'])->count());
        $this->assertAuditedOperations($fixture, ['race-credit-first'], 1);
    }

    public function test_concurrent_receipts_do_not_overallocate_one_invoice(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $first = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '70.00'),
            'idempotency_key' => 'race-allocation-first',
            'hold_invoice_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_invoices');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '70.00'),
            'idempotency_key' => 'race-allocation-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(409, $secondResult['status']);
        $this->assertInvoiceLedger($fixture, (int) $invoice->id, '100.00', '70.00', '30.00');
        self::assertSame(1, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpReceiptAllocation::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->where('idempotency_key', 'race-allocation-first')->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->where('idempotency_key', 'race-allocation-second')->count());
        $this->assertAuditedOperations($fixture, ['race-allocation-first'], 1);
    }

    public function test_receipt_reversal_and_new_allocation_preserve_one_effective_payment(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $original = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => app(OfficeErpReceipts::class)->post($fixture['context'], $this->receiptInput($invoice, '100.00'), 'race-reversal-original'),
        ));
        $first = $this->worker('reverse', $this->financialPayload($fixture, [
            'receipt_id' => (int) $original->id,
            'reason' => 'Correct original receipt',
            'idempotency_key' => 'race-reversal-first',
            'hold_invoice_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_invoices');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '100.00'),
            'idempotency_key' => 'race-reversal-receipt-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(201, $secondResult['status']);
        self::assertSame(1, OfficeErpReceiptReversal::query()->where('receipt_id', $original->id)->count());
        self::assertSame(2, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(2, OfficeErpReceiptAllocation::query()->where('invoice_id', $invoice->id)->count());
        $this->assertInvoiceLedger($fixture, (int) $invoice->id, '100.00', '100.00', '0.00');
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);
        $this->assertAuditedOperations($fixture, ['race-reversal-original', 'race-reversal-first', 'race-reversal-receipt-second'], 3);
    }

    public function test_staged_proof_can_be_claimed_by_only_one_receipt_operation(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        [$firstInvoice, $secondInvoice] = [
            $this->issueRaceInvoice($fixture, $fixture['contracts'][0]),
            $this->issueRaceInvoice($fixture, $fixture['contracts'][1]),
        ];
        $proof = $this->stageRaceProof($fixture);
        $first = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => [...$this->receiptInput($firstInvoice, '25.00'), 'proof_id' => $proof['id']],
            'idempotency_key' => 'race-proof-first',
            'hold_proof_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_receipt_proofs');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => [...$this->receiptInput($secondInvoice, '25.00'), 'proof_id' => $proof['id']],
            'idempotency_key' => 'race-proof-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame(201, $results[0]['status']);
        self::assertSame(422, $results[1]['status']);
        $stored = OfficeErpReceiptProof::query()->findOrFail($proof['id']);
        self::assertNotNull($stored->receipt_id);
        self::assertNotNull($stored->claimed_at);
        self::assertSame(1, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpReceiptAllocation::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->whereIn('idempotency_key', ['race-proof-first', 'race-proof-second'])->count());
        Storage::disk('local')->assertExists($stored->path);
        self::assertSame(0, $results[0]['transaction_level']);
        self::assertSame(0, $results[1]['transaction_level']);
        $this->assertAuditedOperations($fixture, ['race-proof-first'], 1);
    }

    public function test_claimed_proof_survives_expired_cleanup_waiting_on_its_row_lock(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $proof = $this->stageRaceProof($fixture);
        $frozenNow = CarbonImmutable::parse('2030-06-15T12:00:00Z');
        $expiresAt = $frozenNow->addSecond();
        DB::table('office_erp_receipt_proofs')->where('id', $proof['id'])->update(['expires_at' => $expiresAt]);
        $first = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => [...$this->receiptInput($invoice, '25.00'), 'proof_id' => $proof['id']],
            'idempotency_key' => 'race-proof-cleanup-claim',
            'frozen_now' => $frozenNow->toIso8601String(),
            'hold_after_proof_claim' => true,
        ]));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertSame($this->signalData($first, 'ready')['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('insert into "office_erp_receipt_allocations"', strtolower($held['lock_query']));
        $second = $this->worker('proof_cleanup', $this->financialPayload($fixture, [
            'now' => $expiresAt->addSecond()->toIso8601String(),
            'contract_id' => (int) $fixture['contracts'][0]->id,
        ]));
        $this->assertWaitingOnProofLock($second);
        $this->release($first);
        $claim = $this->workerResult($first);
        $cleanup = $this->workerResult($second);

        self::assertSame(201, $claim['status']);
        self::assertSame(0, $cleanup['deleted']);
        $stored = OfficeErpReceiptProof::query()->findOrFail($proof['id']);
        self::assertNotNull($stored->receipt_id);
        Storage::disk('local')->assertExists($stored->path);
        self::assertSame(0, $claim['transaction_level']);
        self::assertSame(0, $cleanup['transaction_level']);
        $this->assertAuditedOperations($fixture, ['race-proof-cleanup-claim'], 1);
    }

    /** @param list<string> $keys */
    private function assertAuditedOperations(array $fixture, array $keys, int $expected): void
    {
        $operationIds = OfficeErpCommercialOperation::query()
            ->where('account_id', $fixture['account_id'])
            ->whereIn('idempotency_key', $keys)
            ->pluck('id');
        self::assertCount($expected, $operationIds);
        self::assertSame($expected, OfficeErpAuditEvent::query()->whereIn('operation_id', $operationIds)->count());
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function reservationPayload(array $fixture, int $contractId, bool $holdAccountLock = false): array
    {
        return [
            'account_id' => $fixture['account_id'],
            'actor_id' => $fixture['actor_id'],
            'membership_id' => $fixture['membership_id'],
            'contract_id' => $contractId,
            'expires_at' => now()->addDay()->toIso8601String(),
            'hold_account_lock' => $holdAccountLock,
        ];
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function invoicePayload(array $fixture, OfficeErpContract $contract, string $key, bool $holdAccountLock = false): array
    {
        return [
            'account_id' => $fixture['account_id'],
            'actor_id' => $fixture['actor_id'],
            'membership_id' => $fixture['membership_id'],
            'contract_id' => (int) $contract->id,
            'revision_id' => (int) $contract->current_revision_id,
            'period' => $fixture['invoice_period'],
            'idempotency_key' => $key,
            'hold_account_lock' => $holdAccountLock,
        ];
    }
}
