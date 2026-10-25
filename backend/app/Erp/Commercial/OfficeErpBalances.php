<?php

namespace App\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpInvoice;

final class OfficeErpBalances
{
    /**
     * @return array{
     *     original_total:string,
     *     adjusted_charge:string,
     *     effective_received:string,
     *     outstanding:string,
     *     settlement_status:string,
     *     overdue:bool
     * }
     */
    public static function forInvoice(OfficeErpInvoice $invoice): array
    {
        $originalTotal = OfficeErpMoney::canonical((string) $invoice->getRawOriginal('original_total'), 'original_total');
        if ($invoice->document_status !== 'issued') {
            return [
                'original_total' => $originalTotal,
                'adjusted_charge' => '0.00',
                'effective_received' => '0.00',
                'outstanding' => '0.00',
                'settlement_status' => 'settled',
                'overdue' => false,
            ];
        }

        $adjustedCharge = $originalTotal;
        foreach ($invoice->adjustments()->get(['direction', 'amount']) as $adjustment) {
            $amount = OfficeErpMoney::canonical((string) $adjustment->getRawOriginal('amount'), 'amount');
            $adjustedCharge = $adjustment->direction === 'credit'
                ? OfficeErpMoney::subtract($adjustedCharge, $amount)
                : OfficeErpMoney::add($adjustedCharge, $amount);
        }

        $effectiveReceived = '0.00';
        $allocations = $invoice->allocations()
            ->whereDoesntHave('receipt.reversal')
            ->get(['amount']);
        foreach ($allocations as $allocation) {
            $effectiveReceived = OfficeErpMoney::add(
                $effectiveReceived,
                OfficeErpMoney::canonical((string) $allocation->getRawOriginal('amount'), 'amount'),
            );
        }

        $outstanding = OfficeErpMoney::subtract($adjustedCharge, $effectiveReceived);
        $settled = OfficeErpMoney::compare($outstanding, '0.00') <= 0;
        $settlementStatus = $settled
            ? 'settled'
            : (OfficeErpMoney::compare($effectiveReceived, '0.00') > 0 ? 'partial' : 'unpaid');
        $timezone = $invoice->contract?->billing_timezone ?? 'America/Sao_Paulo';
        $overdue = ! $settled && now($timezone)->toDateString() > $invoice->due_on->toDateString();

        return [
            'original_total' => $originalTotal,
            'adjusted_charge' => $adjustedCharge,
            'effective_received' => $effectiveReceived,
            'outstanding' => $outstanding,
            'settlement_status' => $settlementStatus,
            'overdue' => $overdue,
        ];
    }
}
