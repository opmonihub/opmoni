<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;

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
