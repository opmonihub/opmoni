<?php

namespace App\Erp\Finance;

use InvalidArgumentException;

final class ErpPaymentAllocationService
{
    /**
     * @param  list<array{amount: string}>  $existingAllocations
     */
    public function assertAllocationAllowed(string $obligationOpenAmount, string $allocationAmount, array $existingAllocations = []): void
    {
        $open = ErpDecimal::parse($obligationOpenAmount);
        $allocation = ErpDecimal::parse($allocationAmount);

        if (! ErpDecimal::isPositive($allocation)) {
            throw new InvalidArgumentException('Allocation amount must be positive.');
        }

        $allocated = ErpDecimal::zero();
        foreach ($existingAllocations as $row) {
            $allocated = ErpDecimal::add($allocated, (string) $row['amount']);
        }

        $remaining = ErpDecimal::sub($open, $allocated);
        if (ErpDecimal::compare($allocation, $remaining) === 1) {
            throw new InvalidArgumentException('Allocation exceeds obligation open amount.');
        }
    }

    public function remainingOpenAmount(string $obligationOpenAmount, array $existingAllocations): string
    {
        $allocated = ErpDecimal::zero();
        foreach ($existingAllocations as $row) {
            $allocated = ErpDecimal::add($allocated, (string) $row['amount']);
        }

        return ErpDecimal::sub($obligationOpenAmount, $allocated);
    }
}
