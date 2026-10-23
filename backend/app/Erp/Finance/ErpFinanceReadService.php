<?php

namespace App\Erp\Finance;

use App\Enums\Erp\ErpEstablishmentResource;
use App\Erp\ErpEstablishmentAccess;
use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ErpFinanceReadService
{
    public function resource(Request $request, string $class, int $id, ?string $field = null): Model
    {
        $context = CurrentCompany::fromRequest($request);
        $model = $class::where('erp_company_id', $context->company->id)->find($id);
        if ($field !== null && ! $model) {
            throw ValidationException::withMessages([$field => 'Registro inválido para esta empresa.']);
        }
        abort_unless($model, 404);
        if ($model instanceof ErpPayment || $model instanceof ErpCashSession) {
            $allowed = $model->newQuery()->whereKey($model->id);
            $model instanceof ErpPayment ? $this->scopePayments($request, $allowed) : $this->scopeCashSessions($request, $allowed);
            if (! $allowed->exists()) {
                if ($field !== null) {
                    throw ValidationException::withMessages([$field => 'Pagamento fora do escopo autorizado.']);
                }
                abort(404);
            }
        }
        if ($field !== null) {
            app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $model, $field);
        } else {
            app(ErpEstablishmentAccess::class)->assertResource($context, $model);
        }

        return $model;
    }

    public function allocation(Request $request, int $id): ErpPaymentAllocation
    {
        $allocation = ErpPaymentAllocation::findOrFail($id);
        $this->resource($request, ErpPayment::class, $allocation->erp_payment_id);
        $this->resource($request, ErpObligation::class, $allocation->erp_obligation_id);

        return $allocation;
    }

    public function scopePayments(Request $request, Builder $query): void
    {
        $companyId = CurrentCompany::fromRequest($request)->company->id;
        $allowedOwn = ErpPayment::select('erp_payments.id')->where('erp_company_id', $companyId);
        app(ErpEstablishmentAccess::class)->apply($allowedOwn, CurrentCompany::fromRequest($request), ErpEstablishmentResource::Payment);
        $this->scopeOwnPaymentReferences($request, $allowedOwn);
        // UNION bounds traversal by distinct Company payment/ancestor pairs, including legacy cycles.
        $sql = 'WITH RECURSIVE payment_ancestry(start_id, ancestor_id) AS (
            SELECT id, reversal_of_id FROM erp_payments WHERE erp_company_id = ? AND reversal_of_id IS NOT NULL
            UNION
            SELECT ancestry.start_id, parent.reversal_of_id FROM payment_ancestry ancestry
            JOIN erp_payments parent ON parent.id = ancestry.ancestor_id AND parent.erp_company_id = ?
            WHERE parent.reversal_of_id IS NOT NULL
        ), unproven_payments(id) AS (
            SELECT erp_payments.id FROM erp_payments WHERE erp_company_id = ? AND erp_payments.id NOT IN ('.$allowedOwn->toSql().')
            UNION
            SELECT id FROM erp_payments WHERE erp_company_id = ? AND reversal_of_id IS NOT NULL
                AND reversal_of_id NOT IN (SELECT id FROM erp_payments WHERE erp_company_id = ?)
            UNION
            SELECT start_id FROM payment_ancestry WHERE start_id = ancestor_id
        )
        SELECT id FROM unproven_payments
        UNION
        SELECT ancestry.start_id FROM payment_ancestry ancestry JOIN unproven_payments denied ON denied.id = ancestry.ancestor_id';
        $query->whereRaw('erp_payments.id NOT IN ('.$sql.')', [$companyId, $companyId, $companyId, ...$allowedOwn->getBindings(), $companyId, $companyId]);
    }

    private function scopeOwnPaymentReferences(Request $request, Builder $query): void
    {
        $context = CurrentCompany::fromRequest($request);
        $access = app(ErpEstablishmentAccess::class);
        $allowedObligations = ErpObligation::select('id')->where('erp_company_id', $context->company->id);
        $access->apply($allowedObligations, $context, ErpEstablishmentResource::Obligation);
        $query->whereNotIn('erp_payments.id', ErpPaymentAllocation::select('erp_payment_id')->whereNotIn('erp_obligation_id', $allowedObligations));
        $allowedSessions = ErpCashSession::select('id')->where('erp_company_id', $context->company->id);
        $access->apply($allowedSessions, $context, ErpEstablishmentResource::CashSession);
        $query->where(fn (Builder $inner) => $inner->whereNull('erp_cash_session_id')->orWhereIn('erp_cash_session_id', $allowedSessions));
        $allowedAccounts = ErpFinancialAccount::select('id')->where('erp_company_id', $context->company->id);
        $access->apply($allowedAccounts, $context, ErpEstablishmentResource::FinancialAccount);
        $query->where(fn (Builder $inner) => $inner->whereNull('erp_financial_account_id')->orWhereIn('erp_financial_account_id', $allowedAccounts));
    }

    public function scopeCashSessions(Request $request, Builder $query): void
    {
        $context = CurrentCompany::fromRequest($request);
        $payments = ErpPayment::select('erp_payments.id')->where('erp_company_id', $context->company->id);
        app(ErpEstablishmentAccess::class)->apply($payments, $context, ErpEstablishmentResource::Payment);
        $this->scopePayments($request, $payments);
        $query->whereNotIn('erp_cash_sessions.id', ErpCashMovement::select('erp_cash_session_id')->whereNotNull('erp_payment_id')->whereNotIn('erp_payment_id', $payments));
    }

    public function assertPaymentReferences(Request $request, ErpPayment $payment): void
    {
        if ($payment->erp_cash_session_id !== null) {
            $this->resource($request, ErpCashSession::class, $payment->erp_cash_session_id);
        }
        if ($payment->erp_financial_account_id !== null) {
            $this->resource($request, ErpFinancialAccount::class, $payment->erp_financial_account_id);
        }
        if ($payment->reversal_of_id !== null) {
            $this->resource($request, ErpPayment::class, $payment->reversal_of_id);
        }
        foreach ($payment->allocations()->get() as $allocation) {
            $this->resource($request, ErpObligation::class, $allocation->erp_obligation_id);
        }
    }

    public function sumAllocations(ErpPayment|ErpObligation $model): string
    {
        $amount = '0.0000';
        foreach ($model->allocations()->get() as $allocation) {
            $amount = ErpDecimal::add($amount, $allocation->amount);
        }

        return $amount;
    }

    public function reversible(ErpPaymentAllocation $allocation): string
    {
        $remaining = $allocation->amount;
        foreach (ErpPaymentAllocation::where('reversal_of_id', $allocation->id)->get() as $row) {
            $remaining = ErpDecimal::add($remaining, $row->amount);
        }

        return $allocation->reversal_of_id === null ? $remaining : '0.0000';
    }

    public function serialize(Model $model, ?Request $request = null): array
    {
        if ($model instanceof ErpPaymentAllocation) {
            return ['id' => $model->id, 'erp_payment_id' => $model->erp_payment_id, 'erp_obligation_id' => $model->erp_obligation_id, 'amount' => $model->amount, 'reversal_of_id' => $model->reversal_of_id, 'remaining_reversible' => $this->reversible($model), 'reason' => $model->reason, ...$this->attribution($model)];
        }
        if ($model instanceof ErpCashMovement) {
            return ['id' => $model->id, 'erp_cash_session_id' => $model->erp_cash_session_id, 'erp_cash_register_id' => $model->erp_cash_register_id, 'erp_establishment_id' => $model->erp_establishment_id, 'direction' => $model->direction, 'kind' => $model->kind, 'amount' => $model->amount, 'currency' => $model->currency, 'reason' => $model->reason, 'erp_payment_id' => $model->erp_payment_id, 'reversal_of_id' => $model->reversal_of_id, ...$this->attribution($model)];
        }
        if ($model instanceof ErpCashSession) {
            $operator = User::find($model->operator_user_id);
            $canOperate = $request !== null && ((int) $model->operator_user_id === (int) $request->user()->id || (CurrentCompany::fromRequest($request)->accessKind === 'direct' && CurrentCompany::fromRequest($request)->membership?->role === 'owner'));

            return ['id' => $model->id, 'erp_cash_register_id' => $model->erp_cash_register_id, 'erp_establishment_id' => $model->erp_establishment_id, 'operator_user_id' => $model->operator_user_id, 'operator' => $operator ? ['id' => $operator->id, 'name' => $operator->name] : null, 'currency' => $model->currency, 'status' => $model->status, 'opening_amount' => $model->opening_amount, 'expected_closing_amount' => $model->expected_closing_amount, 'declared_closing_amount' => $model->declared_closing_amount, 'closing_difference' => $model->closing_difference, 'opened_at' => $model->opened_at?->toISOString(), 'closed_at' => $model->closed_at?->toISOString(), 'closed_by_user_id' => $model->closed_by_user_id, 'can_operate' => $canOperate && $model->status === 'open' && $model->currency !== null && $model->cashRegister?->status === 'active', ...app(ErpCashLedger::class)->totals($model)];
        }
        if ($model instanceof ErpCashRegister) {
            return ['id' => $model->id, 'name' => $model->name, 'erp_establishment_id' => $model->erp_establishment_id, 'status' => $model->status];
        }
        if ($model instanceof ErpFinancialAccount) {
            return ['id' => $model->id, 'name' => $model->name, 'erp_establishment_id' => $model->erp_establishment_id, 'account_type' => $model->account_type, 'currency' => $model->currency, 'status' => $model->status];
        }
        $base = ['id' => $model->id, 'erp_establishment_id' => $model->erp_establishment_id, 'erp_financial_account_id' => $model->erp_financial_account_id, 'amount' => $model->amount, 'currency' => $model->currency, 'direction' => $model->direction, 'status' => $model->status, 'source_type' => $model->source_type, 'source_id' => $model->source_id, 'source_operation_key' => $model->source_operation_key, 'reversal_of_id' => $model->reversal_of_id, ...$this->attribution($model)];
        if ($model instanceof ErpPayment) {
            $reversal = ErpPayment::where('erp_company_id', $model->erp_company_id)->where('reversal_of_id', $model->id)->first();
            $effectiveStatus = $model->reversal_of_id !== null ? 'compensation' : ($reversal !== null ? 'reversed' : $model->status);
            $net = $this->sumAllocations($model);

            return $base + ['method' => $model->method, 'erp_cash_session_id' => $model->erp_cash_session_id, 'reason' => $model->reason, 'effective_status' => $effectiveStatus, 'reversal_id' => $reversal?->id, 'allocated_amount' => $net, 'available_amount' => $effectiveStatus === 'posted' ? ErpDecimal::sub($model->amount, $net) : '0.0000', 'needs_reconciliation' => $model->direction === null,
                'allocation_ids' => $model->allocations()->orderBy('id')->pluck('id')->all(),
                'cash_movement_ids' => ErpCashMovement::where('erp_company_id', $model->erp_company_id)->where('erp_payment_id', $model->id)->orderBy('id')->pluck('id')->all(),
                'correction' => ['permission' => 'erp.payments.reverse', 'workflow' => $model->direction === null ? 'reconciliation' : ($effectiveStatus === 'posted' ? 'payment_reversal' : 'unavailable'), 'requires_cash_target' => $model->method === 'cash' && $model->erp_cash_session_id !== null && ErpCashSession::find($model->erp_cash_session_id)?->status === 'closed'],
            ];
        }

        return $base + ['category' => $model->category, 'due_on' => $model->due_on?->format('Y-m-d'), 'open_amount' => $model->open_amount, 'allocated_amount' => $this->sumAllocations($model), 'return_of_id' => $model->return_of_id, 'erp_contact_id' => $model->erp_contact_id, 'return_credit_ids' => ErpObligation::where('erp_company_id', $model->erp_company_id)->where('return_of_id', $model->id)->pluck('id')->all()];
    }

    private function attribution(Model $model): array
    {
        $actor = $model->posted_by_user_id ? User::find($model->posted_by_user_id) : null;

        return ['posted_by_user_id' => $model->posted_by_user_id, 'actor' => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null, 'posted_at' => $model->posted_at?->toISOString()];
    }

    public function index(Request $request, string $class): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $query = $class::where('erp_company_id', $context->company->id);
        app(ErpEstablishmentAccess::class)->apply($query, $context, ErpEstablishmentResource::forModel($class));
        if ($class === ErpPayment::class) {
            $this->scopePayments($request, $query);
        } elseif ($class === ErpCashSession::class) {
            $this->scopeCashSessions($request, $query);
        }
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'erp_establishment_id' => ['sometimes', 'integer', 'min:1'], 'erp_financial_account_id' => ['sometimes', 'integer', 'min:1'],
            'erp_cash_register_id' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', 'string'],
            'direction' => ['sometimes', 'string'], 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'due_on' => ['sometimes', 'date_format:Y-m-d'], 'eligible_for_payment_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (isset($filters['erp_establishment_id'])) {
            app(ErpEstablishmentAccess::class)->assertRelated($context, (int) $filters['erp_establishment_id']);
            $query->where('erp_establishment_id', $filters['erp_establishment_id']);
        }
        foreach (['erp_financial_account_id' => ErpFinancialAccount::class, 'erp_cash_register_id' => ErpCashRegister::class] as $field => $parent) {
            if (isset($filters[$field])) {
                if (! (new $class)->isFillable($field)) {
                    throw ValidationException::withMessages([$field => 'Filtro indisponível para este recurso.']);
                }
                $this->resource($request, $parent, (int) $filters[$field], $field);
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['currency'])) {
            abort_unless((new $class)->isFillable('currency'), 422);
            $query->where('currency', $filters['currency']);
        }
        if (isset($filters['direction'])) {
            $allowed = $class === ErpObligation::class ? ['receivable', 'payable'] : ($class === ErpPayment::class ? ['in', 'out', 'unknown'] : []);
            if (! in_array($filters['direction'], $allowed, true)) {
                throw ValidationException::withMessages(['direction' => 'Direção de filtro inválida.']);
            }
            $filters['direction'] === 'unknown' ? $query->whereNull('direction') : $query->where('direction', $filters['direction']);
        }
        if (isset($filters['status'])) {
            $allowed = match ($class) {
                ErpPayment::class => ['posted', 'reversed', 'compensation'], ErpObligation::class => ['open', 'settled', 'reversed'], ErpCashSession::class => ['open', 'closed'], default => ['active', 'archived']
            };
            if (! in_array($filters['status'], $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'Situação de filtro inválida.']);
            }
            if ($class === ErpPayment::class) {
                if ($filters['status'] === 'compensation') {
                    $query->whereNotNull('reversal_of_id');
                } else {
                    $query->whereNull('reversal_of_id');
                    $reversals = ErpPayment::select('reversal_of_id')->where('erp_company_id', $context->company->id)->whereNotNull('reversal_of_id');
                    $filters['status'] === 'reversed' ? $query->whereIn('id', $reversals) : $query->whereNotIn('id', $reversals)->where('status', 'posted');
                }
            } else {
                $query->where('status', $filters['status']);
            }
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if (isset($filters[$filter])) {
                $column = $class === ErpCashSession::class ? 'opened_at' : ((new $class)->isFillable('posted_at') ? 'posted_at' : 'created_at');
                $query->whereDate($column, $operator, $filters[$filter]);
            }
        }
        if (isset($filters['due_on'])) {
            abort_unless($class === ErpObligation::class, 422);
            $query->whereDate('due_on', $filters['due_on']);
        }
        if (isset($filters['eligible_for_payment_id'])) {
            abort_unless($class === ErpObligation::class, 422);
            $payment = $this->resource($request, ErpPayment::class, (int) $filters['eligible_for_payment_id'], 'eligible_for_payment_id');
            $query->where('status', 'open')->where('currency', $payment->currency)->where('direction', $payment->direction === 'in' ? 'receivable' : 'payable');
            if ($payment->direction === null || $this->serialize($payment)['effective_status'] !== 'posted') {
                $query->whereRaw('1 = 0');
            }
            $party = app(ErpFinanceParticipant::class)->paymentParty($payment);
            if ($party !== null) {
                $query->where('erp_contact_id', $party);
            }
            if ($payment->erp_financial_account_id !== null) {
                $query->where(fn (Builder $inner) => $inner->whereNull('erp_financial_account_id')->orWhere('erp_financial_account_id', $payment->erp_financial_account_id));
            }
        }

        return $this->page($request, $query);
    }

    public function page(Request $request, Builder $query, array $extra = []): JsonResponse
    {
        $totals = [];
        if ($query->getModel() instanceof ErpPayment || $query->getModel() instanceof ErpObligation) {
            foreach ((clone $query)->get() as $model) {
                $row = $this->serialize($model);
                $currency = $row['currency'];
                $totals[$currency] ??= ['currency' => $currency, 'amount' => '0.0000', 'open_amount' => '0.0000', 'available_amount' => '0.0000', 'receivable_open' => '0.0000', 'payable_open' => '0.0000', 'net_exposure' => '0.0000', 'overdue_open' => '0.0000'];
                if ($model instanceof ErpObligation) {
                    $direction = $model->direction === 'receivable' ? 'receivable_open' : 'payable_open';
                    $totals[$currency][$direction] = bcadd($totals[$currency][$direction], $model->open_amount, 4);
                    $totals[$currency]['net_exposure'] = bcsub($totals[$currency]['receivable_open'], $totals[$currency]['payable_open'], 4);
                    if ($model->due_on !== null && $model->due_on->lt(today())) {
                        $totals[$currency]['overdue_open'] = bcadd($totals[$currency]['overdue_open'], $model->open_amount, 4);
                    }
                }
                foreach (['amount', 'open_amount', 'available_amount'] as $field) {
                    $totals[$currency][$field] = bcadd($totals[$currency][$field], $row[$field] ?? '0.0000', 4);
                }
            }
        }
        $page = $query->orderByDesc($query->getModel()->getTable().'.id')->paginate(min(100, max(1, (int) $request->query('per_page', 15))));

        return response()->json(['data' => $page->getCollection()->map(fn (Model $model) => $this->serialize($model, $request))->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'totals' => array_values($totals)], ...$extra]);
    }
}
