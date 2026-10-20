<?php

namespace App\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ErpCashLedger
{
    public function session(Request $request, int $id, bool $operating = true): ErpCashSession
    {
        $this->assertTransaction();
        $context = CurrentCompany::fromRequest($request);
        $session = ErpCashSession::where('erp_company_id', $context->company->id)->lockForUpdate()->find($id);
        if (! $session) {
            throw ValidationException::withMessages(['erp_cash_session_id' => 'Sessão de caixa inválida.']);
        }
        app(ErpFinanceReadService::class)->resource($request, ErpCashSession::class, $id, 'erp_cash_session_id');
        app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $session, 'erp_cash_session_id');
        $register = ErpCashRegister::where('erp_company_id', $context->company->id)->findOrFail($session->erp_cash_register_id);
        app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $register, 'erp_cash_session_id');
        abort_unless((int) $session->operator_user_id === (int) $request->user()->id || ($context->accessKind === 'direct' && $context->membership?->role === 'owner'), 403, 'Caixa pertence a outro operador');
        if ($operating) {
            if ($session->status !== 'open' || $register->status !== 'active' || $session->currency === null) {
                throw ValidationException::withMessages(['erp_cash_session_id' => 'Selecione um caixa aberto com moeda conciliada.']);
            }
            app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $session->erp_establishment_id);
        }

        return $session;
    }

    public function totals(ErpCashSession $session): array
    {
        if ($session->currency === null) {
            return ['movements_in' => null, 'movements_out' => null, 'movements_net' => null, 'expected_balance' => null, 'needs_reconciliation' => true];
        }
        $in = $out = '0.0000';
        foreach (ErpCashMovement::where('erp_company_id', $session->erp_company_id)->where('erp_cash_session_id', $session->id)->get() as $movement) {
            if ($movement->direction === 'in') {
                $in = bcadd($in, $movement->amount, 4);
            } else {
                $out = bcadd($out, $movement->amount, 4);
            }
        }
        $net = bcsub($in, $out, 4);

        return ['movements_in' => $in, 'movements_out' => $out, 'movements_net' => $net, 'expected_balance' => bcadd($session->opening_amount, $net, 4), 'needs_reconciliation' => false];
    }

    public function append(Request $request, ErpCashSession $session, string $direction, string $kind, string $amount, string $reason, string $key, ?int $paymentId = null, ?int $reversalId = null): ErpCashMovement
    {
        $this->assertTransaction();
        $session = $this->session($request, $session->id);
        abort_unless(in_array($direction, ['in', 'out'], true) && ErpDecimal::isPositive($amount), 422);
        $balance = $this->totals($session)['expected_balance'];
        if ($direction === 'out' && bccomp($amount, $balance, 4) > 0) {
            throw ValidationException::withMessages(['amount' => 'Saída excede o saldo registrado do caixa.']);
        }

        $after = $direction === 'in' ? bcadd($balance, $amount, 4) : bcsub($balance, $amount, 4);
        if (bccomp($after, '999999999999999999.9999', 4) > 0) {
            throw ValidationException::withMessages(['amount' => 'Movimento excede o limite de saldo do caixa.']);
        }

        return ErpCashMovement::create([
            'erp_company_id' => $session->erp_company_id, 'erp_establishment_id' => $session->erp_establishment_id,
            'erp_cash_register_id' => $session->erp_cash_register_id, 'erp_cash_session_id' => $session->id,
            'currency' => $session->currency, 'direction' => $direction, 'kind' => $kind, 'amount' => $amount,
            'reason' => $reason, 'source_operation_key' => $key, 'erp_payment_id' => $paymentId, 'reversal_of_id' => $reversalId,
            'posted_by_user_id' => $request->user()->id, 'posted_at' => now(),
        ]);
    }

    public function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Cash ledger must join the caller transaction.');
        }
    }
}
