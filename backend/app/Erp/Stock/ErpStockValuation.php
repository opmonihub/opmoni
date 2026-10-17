<?php

namespace App\Erp\Stock;

use App\Erp\Finance\ErpDecimal;
use InvalidArgumentException;

final class ErpStockValuation
{
    public const FIFO = 'fifo';

    public const LIFO = 'lifo';

    /**
     * @param  list<array{quantity: string, unit_cost: string}>  $receipts
     * @return array{total_cost: string, allocations: list<array{quantity: string, unit_cost: string}>}
     */
    public function consume(string $method, string $quantity, array $receipts): array
    {
        $needed = ErpDecimal::parse($quantity);
        if (! ErpDecimal::isPositive($needed)) {
            throw new InvalidArgumentException('Issue quantity must be positive.');
        }

        $layers = $receipts;
        if ($method === self::LIFO) {
            $layers = array_reverse($receipts);
        }

        $remaining = $needed;
        $totalCost = ErpDecimal::zero();
        $allocations = [];

        foreach ($layers as $layer) {
            if (ErpDecimal::compare($remaining, ErpDecimal::zero()) !== 1) {
                break;
            }

            $available = ErpDecimal::parse((string) $layer['quantity']);
            if (! ErpDecimal::isPositive($available)) {
                continue;
            }

            $take = ErpDecimal::compare($available, $remaining) === 1 ? $remaining : $available;
            $unitCost = ErpDecimal::parse((string) $layer['unit_cost']);
            $totalCost = ErpDecimal::add($totalCost, ErpDecimal::mul($take, $unitCost));
            $allocations[] = ['quantity' => $take, 'unit_cost' => $unitCost];
            $remaining = ErpDecimal::sub($remaining, $take);
        }

        if (ErpDecimal::compare($remaining, ErpDecimal::zero()) === 1) {
            throw new InvalidArgumentException('Insufficient stock for valuation.');
        }

        return ['total_cost' => $totalCost, 'allocations' => $allocations];
    }
}
