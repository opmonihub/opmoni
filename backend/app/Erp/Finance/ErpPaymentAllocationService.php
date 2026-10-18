<?php

namespace App\Erp\Finance;

use InvalidArgumentException;

final class ErpPaymentAllocationService
{
    public function assertAllocationAllowed(string $obligationOpenAmount, string $allocationAmount): void
    {
        $allocation = ErpDecimal::parse($allocationAmount);
        if (! ErpDecimal::isPositive($allocation)) {
            throw new InvalidArgumentException('Allocation amount must be positive.');
        }
        if (ErpDecimal::compare($allocation, $obligationOpenAmount) === 1) {
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
