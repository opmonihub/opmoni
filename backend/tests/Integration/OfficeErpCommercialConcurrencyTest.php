<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Erp\Commercial\OfficeErpContract;
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
}
