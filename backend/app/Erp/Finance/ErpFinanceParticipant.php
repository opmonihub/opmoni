<?php

namespace App\Erp\Finance;

use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            ->where('erp_company_id', $companyId)
            ->where('source_type', 'erp_sale')
            ->where('source_id', $saleId)
            ->where('source_operation_key', $operationKey)
            ->first();
        if ($existing) {
            abort_unless($existing->erp_establishment_id === $establishmentId && $existing->currency === $currency && ErpDecimal::compare($existing->amount, $amount) === 0, 409, 'Origem financeira divergente');

            return $existing;
        }

        return ErpObligation::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $establishmentId,
            'direction' => 'receivable',
            'category' => 'sale',
            'erp_contact_id' => ErpSale::where('erp_company_id', $companyId)->findOrFail($saleId)->erp_contact_id,
            'party_snapshot' => ErpSale::where('erp_company_id', $companyId)->findOrFail($saleId)->party_snapshot,
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

        DB::table('erp_companies')->where('id', $companyId)->lockForUpdate()->first();
        $identity = ['erp_company_id' => $companyId, 'source_type' => 'erp_purchase', 'source_id' => $purchaseId, 'source_operation_key' => $operationKey];
        $existing = ErpObligation::where($identity)->first();
        if ($existing) {
            abort_unless($existing->erp_establishment_id === $establishmentId && $existing->currency === $currency && ErpDecimal::compare($existing->amount, $amount) === 0, 409, 'Origem financeira divergente');

            return $existing;
        }

        return ErpObligation::create($identity + ['erp_establishment_id' => $establishmentId, 'erp_contact_id' => ErpPurchase::where('erp_company_id', $companyId)->findOrFail($purchaseId)->erp_contact_id, 'party_snapshot' => ErpPurchase::where('erp_company_id', $companyId)->findOrFail($purchaseId)->supplier_snapshot, 'direction' => 'payable', 'category' => 'purchase', 'amount' => ErpDecimal::parse($amount), 'open_amount' => ErpDecimal::parse($amount), 'currency' => $currency, 'status' => 'open', 'posted_by_user_id' => $postedByUserId, 'posted_at' => now()]);
    }

    /** Joins the sale posting transaction; lock the cash session before finance rows. */
    public function postInlineSalePayment(Request $request, ErpSale $sale, ?array $paymentPayload, string $key): array
    {
        $this->assertInCallerTransaction();
        $context = CurrentCompany::fromRequest($request);
        abort_unless($sale->erp_company_id === $context->company->id && $sale->status === 'posted', 422);
        if ($paymentPayload !== null) {
            $context->assertPermission('erp.payments.store', true);
            $context->assertPermission('erp.payments.allocate', true);
            if ($paymentPayload['body']['method'] === 'cash') {
                $context->assertPermission('erp.cash-sessions.movements.store', true);
                if (empty($paymentPayload['body']['erp_cash_session_id'])) {
                    throw ValidationException::withMessages(['payment.erp_cash_session_id' => 'Selecione um caixa aberto.']);
                }
                app(ErpCashLedger::class)->session($request, $paymentPayload['body']['erp_cash_session_id']);
            }
            app(ErpFinanceOperation::class)->assertAuthorized($request, ErpFinanceOperation::PAYMENT, $paymentPayload);
        }
        $obligation = $this->postReceivableForSale($sale->erp_company_id, $sale->erp_establishment_id, $sale->id, $sale->total_amount, $sale->currency, $key.'-obligation', $request->user()->id);
        $refs = ['obligation_id' => $obligation->id];
        if ($paymentPayload !== null) {
            $paid = app(ErpFinanceOperation::class)->post($request, ErpFinanceOperation::PAYMENT, $paymentPayload, $key.'-payment');
            $payment = ErpPayment::where('erp_company_id', $sale->erp_company_id)->findOrFail($paid['body']['data']['id']);
            $payment->update(['source_type' => 'erp_sale', 'source_id' => $sale->id]);
            $this->allocatePayment($sale->erp_company_id, $payment->id, $obligation->id, $payment->amount, $key.'-allocation', $request->user()->id, app(ErpPaymentAllocationService::class));
            $refs['payment_refs'] = $paid['resource_refs'];
            $refs['payment_id'] = $payment->id;
        }

        return $refs;
    }

    public function reverseSourceObligation(int $companyId, string $sourceType, int $sourceId, string $returnType, int $returnId, ?int $actorId): void
    {
        $this->assertInCallerTransaction();
        $obligations = ErpObligation::where('erp_company_id', $companyId)->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->get();
        abort_unless($obligations->count() === 1, 422, 'Origem exige conciliação financeira');
        $obligation = $obligations->sole();
        $this->postReturnCredit($companyId, $obligation->id, $returnType, $returnId, $obligation->amount, $returnType.'-'.$returnId.'-credit', $actorId);
    }

    public function postReturnCredit(int $companyId, int $obligationId, string $returnType, int $returnId, string $amount, string $key, ?int $actorId): ErpObligation
    {
        $this->assertInCallerTransaction();
        $class = match ($returnType) {
            'erp_sale_return' => ErpSaleReturn::class,
            'erp_purchase_return' => ErpPurchaseReturn::class,
            default => throw new InvalidArgumentException('Unsupported return source.'),
        };
        $return = $class::where('erp_company_id', $companyId)->lockForUpdate()->findOrFail($returnId);
        $sourceType = $returnType === 'erp_sale_return' ? 'erp_sale' : 'erp_purchase';
        $sourceId = $returnType === 'erp_sale_return' ? $return->erp_sale_id : $return->erp_purchase_id;
        $sourceClass = $returnType === 'erp_sale_return' ? ErpSale::class : ErpPurchase::class;
        $source = $sourceClass::where('erp_company_id', $companyId)->findOrFail($sourceId);
        abort_unless($source->status === 'posted', 422, 'Origem não está lançada');
        $obligation = ErpObligation::where('erp_company_id', $companyId)->lockForUpdate()->findOrFail($obligationId);
        abort_unless($obligation->currency === $source->currency, 422, 'Moeda original incompatível');
        abort_unless($obligation->source_type === $sourceType && $obligation->source_id === $sourceId && $obligation->erp_establishment_id === $return->erp_establishment_id && in_array($obligation->status, ['open', 'settled'], true), 422, 'Origem financeira incompatível');
        $amount = ErpDecimal::parse($amount);
        abort_unless(ErpDecimal::isPositive($amount) && ErpDecimal::compare($return->total_amount, $amount) === 0, 422, 'Crédito difere da devolução aceita');
        $existing = ErpObligation::where('erp_company_id', $companyId)->where('source_type', $returnType)->where('source_id', $returnId)->first();
        if ($existing) {
            abort_unless($existing->return_of_id === $obligation->id && $existing->currency === $obligation->currency && ErpDecimal::compare($existing->amount, $amount) === 0, 409, 'Devolução financeira divergente');

            return $existing;
        }
        abort_unless($return->status === 'draft', 422, 'Devolução não está em rascunho');
        $credited = '0.0000';
        foreach (ErpObligation::where('erp_company_id', $companyId)->where('return_of_id', $obligation->id)->get() as $credit) {
            $credited = ErpDecimal::add($credited, $credit->amount);
        }
        abort_if(ErpDecimal::compare(ErpDecimal::add($credited, $amount), $obligation->amount) > 0, 422, 'Crédito excede o valor original');

        return ErpObligation::create([
            'erp_company_id' => $companyId, 'erp_establishment_id' => $obligation->erp_establishment_id,
            'erp_contact_id' => $obligation->erp_contact_id, 'erp_financial_account_id' => $obligation->erp_financial_account_id,
            'party_snapshot' => $obligation->party_snapshot, 'direction' => $obligation->direction === 'receivable' ? 'payable' : 'receivable',
            'category' => 'return', 'amount' => $amount, 'open_amount' => $amount, 'currency' => $obligation->currency, 'status' => 'open',
            'source_type' => $returnType, 'source_id' => $returnId, 'source_operation_key' => $key, 'return_of_id' => $obligation->id,
            'posted_by_user_id' => $actorId, 'posted_at' => now(),
        ]);
    }

    public function reverseAllocation(int $companyId, int $allocationId, ?string $amount, string $reason, string $key, int $actorId): ErpPaymentAllocation
    {
        $this->assertInCallerTransaction();
        DB::table('erp_companies')->where('id', $companyId)->lockForUpdate()->first();
        $reference = ErpPaymentAllocation::findOrFail($allocationId);
        $payment = ErpPayment::where('erp_company_id', $companyId)->lockForUpdate()->findOrFail($reference->erp_payment_id);
        $obligation = ErpObligation::where('erp_company_id', $companyId)->lockForUpdate()->findOrFail($reference->erp_obligation_id);
        $original = ErpPaymentAllocation::lockForUpdate()->findOrFail($allocationId);
        abort_unless($original->reversal_of_id === null && ErpDecimal::isPositive($original->amount) && in_array($obligation->status, ['open', 'settled'], true), 422, 'Alocação não reversível');
        $remaining = app(ErpFinanceReadService::class)->reversible($original);
        $amount = $amount ?? $remaining;
        abort_unless(ErpDecimal::isPositive($amount) && ErpDecimal::compare($amount, $remaining) <= 0, 422, 'Correção excede saldo reversível');
        $open = ErpDecimal::add($obligation->open_amount, $amount);
        abort_if(ErpDecimal::compare($open, $obligation->amount) > 0, 422, 'Saldo original exige conciliação');
        $reversal = ErpPaymentAllocation::create(['erp_payment_id' => $payment->id, 'erp_obligation_id' => $obligation->id, 'amount' => ErpDecimal::sub('0', $amount), 'reversal_of_id' => $original->id, 'reason' => $reason, 'source_operation_key' => $key, 'posted_by_user_id' => $actorId, 'posted_at' => now()]);
        $obligation->update(['open_amount' => $open, 'status' => 'open']);

        return $reversal;
    }

    public function paymentParty(ErpPayment $payment): ?int
    {
        $class = match ($payment->source_type) {
            'erp_sale' => ErpSale::class,
            'erp_purchase' => ErpPurchase::class,
            default => null,
        };
        if ($class === null) {
            return null;
        }
        $source = $class::where('erp_company_id', $payment->erp_company_id)->find($payment->source_id);
        abort_unless($source && $source->status === 'posted' && $source->currency === $payment->currency, 422, 'Origem do pagamento incompatível');

        return $source->erp_contact_id;
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
        abort_unless($payment->status === 'posted' && $payment->reversal_of_id === null && ! ErpPayment::where('erp_company_id', $companyId)->where('reversal_of_id', $payment->id)->exists(), 422, 'Pagamento indisponível');
        $available = ErpDecimal::sub((string) $payment->amount, app(ErpFinanceReadService::class)->sumAllocations($payment));
        abort_if(ErpDecimal::compare($amount, $available) === 1, 422, 'Alocação excede saldo do pagamento');

        $obligation = ErpObligation::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($obligationId)
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless($obligation->status === 'open' && $payment->currency === $obligation->currency, 422, 'Obrigação ou moeda incompatível');
        if ($payment->direction === null) {
            abort(response()->json(['code' => 'legacy_operation_requires_reconciliation', 'message' => 'Direção histórica não conciliada.'], 409));
        }
        abort_unless(($payment->direction === 'in' && $obligation->direction === 'receivable') || ($payment->direction === 'out' && $obligation->direction === 'payable'), 422, 'Direção incompatível');
        abort_unless($payment->erp_financial_account_id === null || $obligation->erp_financial_account_id === null || $payment->erp_financial_account_id === $obligation->erp_financial_account_id, 422, 'Conta financeira incompatível');
        $party = $this->paymentParty($payment);
        abort_unless($party === null || $party === $obligation->erp_contact_id, 422, 'Parte financeira incompatível');
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
