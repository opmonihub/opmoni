<?php

namespace App\Erp\Finance;

use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
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

    public function postPayableForPurchase(int $companyId, int $establishmentId, int $purchaseId, string $amount, string $currency, string $operationKey, ?int $postedByUserId): ErpObligation
    {
        $this->assertInCallerTransaction();

        return ErpObligation::query()->firstOrCreate(
            ['erp_company_id' => $companyId, 'source_type' => 'erp_purchase', 'source_id' => $purchaseId, 'source_operation_key' => $operationKey],
            ['erp_establishment_id' => $establishmentId, 'direction' => 'payable', 'category' => 'purchase', 'amount' => $amount, 'open_amount' => $amount, 'currency' => $currency, 'status' => 'open', 'posted_by_user_id' => $postedByUserId, 'posted_at' => now()],
        );
    }

    public function reverseSourceObligation(int $companyId, string $sourceType, int $sourceId, string $returnType, int $returnId, ?int $actorId): void
    {
        $this->assertInCallerTransaction();
        $obligations = ErpObligation::query()->where('erp_company_id', $companyId)->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->get();
        abort_if($obligations->isEmpty(), 422, 'Origem sem obrigação financeira conciliada');
        foreach ($obligations as $obligation) {
            abort_unless($obligation->status === 'open' && ! $obligation->allocations()->exists(), 422, 'Devolução com pagamentos exige conciliação financeira específica');
            ErpObligation::query()->create([
                'erp_company_id' => $companyId, 'erp_establishment_id' => $obligation->erp_establishment_id,
                'direction' => $obligation->direction, 'category' => 'return',
                'amount' => ErpDecimal::sub(ErpDecimal::zero(), (string) $obligation->amount), 'open_amount' => ErpDecimal::zero(),
                'currency' => $obligation->currency, 'status' => 'reversed', 'source_type' => $returnType, 'source_id' => $returnId,
                'source_operation_key' => $returnType.'-'.$returnId.'-obligation-'.$obligation->id, 'reversal_of_id' => $obligation->id,
                'posted_by_user_id' => $actorId, 'posted_at' => now(),
            ]);
            $obligation->update(['status' => 'reversed', 'open_amount' => ErpDecimal::zero()]);
        }
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
            ->whereHas('payment', fn ($query) => $query->where('erp_company_id', $companyId))
            ->whereHas('obligation', fn ($query) => $query->where('erp_company_id', $companyId))
            ->where('erp_payment_id', $paymentId)
            ->where('erp_obligation_id', $obligationId)
            ->where('source_operation_key', $sourceOperationKey)
            ->first();
        if ($existing) {
            abort_unless(ErpDecimal::compare((string) $existing->amount, $amount) === 0, 409, 'Chave de operação reutilizada com valor diferente');

            return $existing;
        }

        $payment = ErpPayment::query()->where('erp_company_id', $companyId)->whereKey($paymentId)->lockForUpdate()->firstOrFail();
        abort_unless($payment->status === 'posted', 422, 'Pagamento indisponível');
        $available = ErpDecimal::sub((string) $payment->amount, (string) $payment->allocations()->sum('amount'));
        abort_if(ErpDecimal::compare($amount, $available) === 1, 422, 'Alocação excede saldo do pagamento');

        $obligation = ErpObligation::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($obligationId)
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless($obligation->status === 'open' && $payment->currency === $obligation->currency, 422, 'Obrigação ou moeda incompatível');
        $allocationAmount = ErpDecimal::parse($amount);
        $allocator->assertAllocationAllowed((string) $obligation->open_amount, $allocationAmount);

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
