<?php

namespace App\Erp\Finance;

use InvalidArgumentException;

final class ErpCashSessionStateMachine
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * @return array{status: string, expected_closing_amount: string, declared_closing_amount: string, closing_difference: string}
     */
    public function close(
        string $currentStatus,
        string $openingAmount,
        string $movementsNet,
        string $declaredClosingAmount,
    ): array {
        if ($currentStatus !== self::STATUS_OPEN) {
            throw new InvalidArgumentException('Cash session is not open.');
        }

        $expected = ErpDecimal::add($openingAmount, $movementsNet);
        $declared = ErpDecimal::parse($declaredClosingAmount);
        $difference = ErpDecimal::sub($declared, $expected);

        return [
            'status' => self::STATUS_CLOSED,
            'expected_closing_amount' => $expected,
            'declared_closing_amount' => $declared,
            'closing_difference' => $difference,
        ];
    }

    public function assertMovementAllowed(string $sessionStatus): void
    {
        if ($sessionStatus !== self::STATUS_OPEN) {
            throw new InvalidArgumentException('Cannot post movement into a closed cash session.');
        }
    }
}
