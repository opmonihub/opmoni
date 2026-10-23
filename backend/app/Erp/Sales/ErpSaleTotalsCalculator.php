<?php

namespace App\Erp\Sales;

use App\Erp\Finance\ErpDecimal;

/**
 * Server-side sale totals derived from sistema {@see ProductUtil::calculateInvoiceTotal}.
 */
final class ErpSaleTotalsCalculator
{
    /**
     * @param  list<array{quantity: string|float|int, unit_price: string|float|int, modifier_total?: string|float|int}>  $lines
     * @param  array{discount_type?: string|null, discount_amount?: string|float|int|null}|null  $discount
     * @return array{subtotal_amount: string, discount_amount: string, tax_amount: string, total_amount: string}
     */
    public function calculate(array $lines, ?array $discount = null, string|float|int|null $taxRatePercent = null): array
    {
        $subtotal = ErpDecimal::zero();
        foreach ($lines as $line) {
            $lineTotal = ErpDecimal::mul(
                ErpDecimal::parse($line['unit_price']),
                ErpDecimal::parse($line['quantity']),
            );
            if (isset($line['modifier_total'])) {
                $lineTotal = ErpDecimal::add($lineTotal, ErpDecimal::parse($line['modifier_total']));
            }
            $subtotal = ErpDecimal::add($subtotal, $lineTotal);
        }

        $discountAmount = ErpDecimal::zero();
        if (is_array($discount)) {
            $type = $discount['discount_type'] ?? 'fixed';
            $raw = ErpDecimal::parse($discount['discount_amount'] ?? '0');
            $discountAmount = $type === 'percentage'
                ? bcdiv(bcmul($subtotal, $raw, 8), '100', ErpDecimal::SCALE)
                : $raw;
        }

        abort_if(ErpDecimal::compare($discountAmount, ErpDecimal::zero()) === -1 || ErpDecimal::compare($discountAmount, $subtotal) === 1, 422, 'Desconto inválido');
        $taxable = ErpDecimal::sub($subtotal, $discountAmount);
        $taxAmount = ErpDecimal::zero();
        if ($taxRatePercent !== null && ErpDecimal::compare(ErpDecimal::parse($taxRatePercent), ErpDecimal::zero()) === 1) {
            $taxAmount = bcdiv(bcmul($taxable, ErpDecimal::parse($taxRatePercent), 8), '100', ErpDecimal::SCALE);
        }

        $total = ErpDecimal::add(ErpDecimal::sub($subtotal, $discountAmount), $taxAmount);

        return [
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $total,
        ];
    }
}
