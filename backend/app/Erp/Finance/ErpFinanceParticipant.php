<?php

namespace App\Erp\Finance;

use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ErpFinanceParticipant
{
    public function postReceivableForSale(
        int $companyId,
        int $establishmentId,
        int $saleId,
        string $amount,
        string $currency,
        string $operationKey,
        ?int $postedByUserId,
    ): ErpObligation {
        $this->assertInCallerTransaction();

        DB::table('erp_companies')->where('id', $companyId)->lockForUpdate()->first();

        $existing = ErpObligation::query()
            ->where('source_type', 'erp_sale')
            ->where('source_id', $saleId)
            ->where('source_operation_key', $operationKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        return ErpObligation::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $establishmentId,
            'direction' => 'receivable',
            'category' => 'sale',
            'amount' => ErpDecimal::parse($amount),
            'open_amount' => ErpDecimal::parse($amount),
            'currency' => $currency,
            'status' => 'open',
            'source_type' => 'erp_sale',
            'source_id' => $saleId,
            'source_operation_key' => $operationKey,
            'posted_by_user_id' => $postedByUserId,
            'posted_at' => now(),
        ]);
    }

    public function allocatePayment(
        int $companyId,
        int $paymentId,
        int $obligationId,
        string $amount,
        string $sourceOperationKey,
        ?int $postedByUserId,
        ErpPaymentAllocationService $allocator,
    ): ErpPaymentAllocation {
        $this->assertInCallerTransaction();

        DB::table('erp_companies')->where('id', $companyId)->lockForUpdate()->first();

        $existing = ErpPaymentAllocation::query()
            ->where('erp_payment_id', $paymentId)
            ->where('erp_obligation_id', $obligationId)
            ->where('source_operation_key', $sourceOperationKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        $obligation = ErpObligation::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($obligationId)
            ->lockForUpdate()
            ->firstOrFail();

        $existingRows = $obligation->allocations()
            ->get(['amount'])
            ->map(fn (ErpPaymentAllocation $row): array => ['amount' => (string) $row->amount])
            ->all();

        $allocationAmount = ErpDecimal::parse($amount);
        $allocator->assertAllocationAllowed((string) $obligation->open_amount, $allocationAmount, $existingRows);

        $allocation = ErpPaymentAllocation::query()->create([
            'erp_payment_id' => $paymentId,
            'erp_obligation_id' => $obligation->id,
            'amount' => $allocationAmount,
            'source_operation_key' => $sourceOperationKey,
            'posted_by_user_id' => $postedByUserId,
            'posted_at' => now(),
        ]);

        $remaining = ErpDecimal::sub((string) $obligation->open_amount, $allocationAmount);
        $obligation->open_amount = $remaining;
        if (ErpDecimal::compare($remaining, ErpDecimal::zero()) === 0) {
            $obligation->status = 'settled';
        }
        $obligation->save();

        return $allocation;
    }

    private function assertInCallerTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Finance participant must join the caller transaction.');
        }
    }
}
