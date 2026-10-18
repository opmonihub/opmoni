<?php

namespace App\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpPaymentAllocationOperation
{
    public const NAME = 'erp.payments.allocate';

    /** @return array{payment_id:int, erp_obligation_id:int, amount:string} */
    public function canonical(Request $request, int|string $payment): array
    {
        $identifiers = ['payment_id' => $payment, 'erp_obligation_id' => $request->input('erp_obligation_id')];
        foreach ($identifiers as $field => $identifier) {
            if (! is_int($identifier) && ! is_string($identifier)) {
                throw ValidationException::withMessages([$field => 'Identificador deve ser inteiro ou texto numérico']);
            }
        }
        $data = Validator::make($identifiers + ['amount' => $request->input('amount')], [
            'payment_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'erp_obligation_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'amount' => ['required', 'string'],
        ])->validate();

        return ['payment_id' => (int) $data['payment_id'], 'erp_obligation_id' => (int) $data['erp_obligation_id'], 'amount' => ErpDecimal::parse($data['amount'])];
    }

    /** @param array{payment_id:int, erp_obligation_id:int, allocation_id?:int} $references */
    public function assertAuthorized(Request $request, array $references): void
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission(self::NAME, true);
        $companyId = $context->company->id;
        $payment = ErpPayment::query()->where('erp_company_id', $companyId)->findOrFail($references['payment_id']);
        $obligation = ErpObligation::query()->where('erp_company_id', $companyId)->find($references['erp_obligation_id']);
        if (! $obligation) {
            throw ValidationException::withMessages(['erp_obligation_id' => 'Registro inválido para esta empresa']);
        }
        foreach ([$payment, $obligation] as $source) {
            app(ErpCatalogScope::class)->assertEstablishmentBelongsToCompany($companyId, $source->erp_establishment_id);
        }
        if (isset($references['allocation_id'])) {
            abort_unless(ErpPaymentAllocation::query()->whereKey($references['allocation_id'])->where('erp_payment_id', $payment->id)->where('erp_obligation_id', $obligation->id)->exists(), 404);
        }
    }

    /** @param array{payment_id:int, erp_obligation_id:int, amount:string} $payload */
    public function post(Request $request, array $payload, string $participantKey): array
    {
        $allocation = app(ErpFinanceParticipant::class)->allocatePayment(
            CurrentCompany::fromRequest($request)->company->id, $payload['payment_id'], $payload['erp_obligation_id'], $payload['amount'],
            $participantKey, $request->user()->id, app(ErpPaymentAllocationService::class),
        );
        $obligation = $allocation->obligation()->firstOrFail();

        return ['status' => 200, 'body' => ['data' => [
            'id' => $allocation->id, 'erp_payment_id' => $allocation->erp_payment_id, 'erp_obligation_id' => $allocation->erp_obligation_id,
            'amount' => ErpDecimal::parse($allocation->amount), 'obligation_open_amount' => ErpDecimal::parse($obligation->open_amount), 'obligation_status' => $obligation->status,
        ]], 'resource_refs' => ['payment_id' => $allocation->erp_payment_id, 'erp_obligation_id' => $allocation->erp_obligation_id, 'allocation_id' => $allocation->id]];
    }
}
