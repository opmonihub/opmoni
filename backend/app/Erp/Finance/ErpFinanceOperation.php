<?php

namespace App\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpFinanceOperation
{
    public const ACCOUNT = 'erp.financial-accounts.store';

    public const OBLIGATION = 'erp.obligations.store';

    public const PAYMENT = 'erp.payments.store';

    public const ALLOCATION_REVERSE = 'erp.payment-allocations.reverse';

    public const PAYMENT_REVERSE = 'erp.payments.reverse';

    public const REGISTER = 'erp.cash-registers.store';

    public const OPEN = 'erp.cash-sessions.store';

    public const MOVEMENT = 'erp.cash-sessions.movements.store';

    public const CLOSE = 'erp.cash-sessions.close';

    public static function names(): array
    {
        return [self::ACCOUNT, self::OBLIGATION, self::PAYMENT, self::ALLOCATION_REVERSE, self::PAYMENT_REVERSE, self::REGISTER, self::OPEN, self::MOVEMENT, self::CLOSE];
    }

    public static function rawInput(Request $request): array
    {
        if ($request->isJson()) {
            $input = json_decode($request->getContent(), true);

            return is_array($input) ? $input : [];
        }
        if (str_starts_with((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($request->getContent(), $input);

            return $input;
        }

        return $request->all();
    }

    public function canonical(Request $request, string $operation, int|string|null $sourceId): array
    {
        $input = self::rawInput($request);
        $rules = match ($operation) {
            self::ACCOUNT => ['name' => ['required', 'string', 'max:255'], 'account_type' => ['sometimes', 'string', 'max:32'], 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'], 'erp_establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1']],
            self::OBLIGATION => ['direction' => ['required', 'in:receivable,payable'], 'category' => ['required', 'string', 'max:32'], 'amount' => ['required', 'string'], 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'], 'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'erp_establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'erp_financial_account_id' => ['sometimes', 'nullable', 'integer', 'min:1']],
            self::PAYMENT => ['direction' => ['required', 'in:in,out'], 'amount' => ['required', 'string'], 'method' => ['required', 'string', 'max:32'], 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'], 'erp_establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'erp_financial_account_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'erp_cash_session_id' => ['sometimes', 'nullable', 'integer', 'min:1']],
            self::REGISTER => ['name' => ['required', 'string', 'max:255'], 'erp_establishment_id' => ['required', 'integer', 'min:1']],
            self::OPEN => ['erp_cash_register_id' => ['required', 'integer', 'min:1'], 'opening_amount' => ['required', 'string'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D']],
            self::CLOSE => ['declared_closing_amount' => ['required', 'string']],
            self::MOVEMENT => ['direction' => ['required', 'in:in,out'], 'kind' => ['required', 'in:deposit,withdrawal'], 'amount' => ['required', 'string'], 'reason' => ['required', 'string', 'max:255']],
            self::ALLOCATION_REVERSE => ['reason' => ['required', 'string', 'max:255'], 'amount' => ['sometimes', 'string']],
            self::PAYMENT_REVERSE => ['reason' => ['required', 'string', 'max:255'], 'erp_cash_session_id' => ['sometimes', 'nullable', 'integer', 'min:1']],
        };
        if (array_diff(array_keys($input), [...array_keys($rules), 'source_operation_key', ...($operation === self::CLOSE ? ['close_operation_key'] : [])]) !== []) {
            throw ValidationException::withMessages(['operation' => 'Conteúdo de operação não suportado.']);
        }
        foreach ($rules as $field => $_) {
            if (str_ends_with($field, '_id') && ($input[$field] ?? null) !== null && ! is_int(($input[$field] ?? null)) && ! is_string(($input[$field] ?? null))) {
                throw ValidationException::withMessages([$field => 'Informe um identificador inteiro.']);
            }
        }
        $body = Validator::make($input, $rules)->validate();
        foreach ($body as $field => &$value) {
            if (str_ends_with($field, '_id') && $value !== null) {
                abort_if(strlen((string) $value) > 16 || (int) $value > 9007199254740991, 422);
                $value = (int) $value;
            }
            if (in_array($field, ['amount', 'opening_amount', 'declared_closing_amount'], true)) {
                if (! preg_match('/^\d{1,18}(?:\.\d{1,4})?$/D', $value)) {
                    throw ValidationException::withMessages([$field => 'Informe um decimal em texto com até quatro casas.']);
                }
                $value = ErpDecimal::parse($value);
                if ($field === 'amount' && ! ErpDecimal::isPositive($value)) {
                    throw ValidationException::withMessages([$field => 'Valor deve ser positivo.']);
                }
            }
        }
        unset($value);
        $defaults = match ($operation) {
            self::ACCOUNT => ['account_type' => 'saving_current', 'currency' => 'BRL', 'erp_establishment_id' => null],
            self::OBLIGATION => ['currency' => 'BRL', 'due_on' => null, 'erp_establishment_id' => null, 'erp_financial_account_id' => null],
            self::PAYMENT => ['currency' => 'BRL', 'erp_establishment_id' => null, 'erp_financial_account_id' => null, 'erp_cash_session_id' => null],
            self::PAYMENT_REVERSE => ['erp_cash_session_id' => null],
            self::ALLOCATION_REVERSE => ['amount' => null],
            default => [],
        };
        $body += $defaults;
        if ($sourceId !== null && ((! is_int($sourceId) && ! is_string($sourceId)) || ! preg_match('/^\d+$/D', (string) $sourceId) || (int) $sourceId < 1 || (int) $sourceId > 9007199254740991)) {
            throw ValidationException::withMessages(['source_id' => 'Identificador inválido.']);
        }

        return ['source_id' => $sourceId === null ? null : (int) $sourceId, 'body' => $body];
    }

    private function sourceClass(string $operation): string
    {
        return match ($operation) {
            self::ACCOUNT => ErpFinancialAccount::class, self::OBLIGATION => ErpObligation::class,
            self::PAYMENT, self::PAYMENT_REVERSE => ErpPayment::class,
            self::REGISTER => ErpCashRegister::class,
            self::OPEN, self::MOVEMENT, self::CLOSE => ErpCashSession::class,
        };
    }

    public function assertAuthorized(Request $request, string $operation, array $references): void
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission($operation, true);
        $read = app(ErpFinanceReadService::class);
        $id = $references['resource_id'] ?? $references['source_id'] ?? null;
        if ($id !== null) {
            if ($operation === self::ALLOCATION_REVERSE) {
                $read->allocation($request, $id);
            } else {
                $read->resource($request, $this->sourceClass($operation), $id);
            }
        }
        foreach (['payments' => ErpPayment::class, 'obligations' => ErpObligation::class, 'sessions' => ErpCashSession::class, 'registers' => ErpCashRegister::class, 'accounts' => ErpFinancialAccount::class] as $field => $class) {
            foreach ($references[$field] ?? [] as $relatedId) {
                $read->resource($request, $class, $relatedId);

            }
        }
        foreach ($references['cashier_sessions'] ?? [] as $sessionId) {
            app(ErpCashLedger::class)->session($request, $sessionId, false);
        }
        $body = $references['body'] ?? [];
        if (array_key_exists('erp_establishment_id', $body)) {
            if ($body['erp_establishment_id'] !== null || empty($body['erp_cash_session_id'])) {
                app(ErpEstablishmentAccess::class)->assertNullableRelated($context, $body['erp_establishment_id']);
            }
        }
        foreach (['erp_financial_account_id' => ErpFinancialAccount::class, 'erp_cash_register_id' => ErpCashRegister::class, 'erp_cash_session_id' => ErpCashSession::class] as $field => $class) {
            if (! empty($body[$field])) {
                $read->resource($request, $class, $body[$field], $field);
                if ($field === 'erp_cash_session_id') {
                    app(ErpCashLedger::class)->session($request, $body[$field], false);
                }
            }
        }
        if ($operation === self::PAYMENT_REVERSE && $id !== null) {
            foreach (ErpPaymentAllocation::where('erp_payment_id', $id)->get() as $allocation) {
                $read->resource($request, ErpObligation::class, $allocation->erp_obligation_id);
            }
        }
        if (in_array($operation, [self::MOVEMENT, self::CLOSE], true) && $id !== null) {
            app(ErpCashLedger::class)->session($request, $id, false);
        }
    }

    public function assertNoLegacy(Request $request, string $operation, string $key): void
    {
        $company = CurrentCompany::fromRequest($request)->company->id;
        $found = match ($operation) {
            self::PAYMENT => ErpPayment::where('erp_company_id', $company)->where('source_operation_key', $key)->exists(),
            self::OBLIGATION => ErpObligation::where('erp_company_id', $company)->where('source_operation_key', $key)->exists(),
            self::CLOSE => ErpCashSession::where('erp_company_id', $company)->where('close_operation_key', $key)->exists(),
            default => false,
        };
        if ($found) {
            abort(response()->json(['code' => 'legacy_operation_requires_reconciliation', 'message' => 'Operação anterior exige conciliação.'], 409));
        }
    }

    public function post(Request $request, string $operation, array $payload, string $key): array
    {
        $context = CurrentCompany::fromRequest($request);
        $companyId = $context->company->id;
        $body = $payload['body'];
        $id = $payload['source_id'];
        $read = app(ErpFinanceReadService::class);
        $cash = app(ErpCashLedger::class);
        $refs = ['sessions' => [], 'cashier_sessions' => [], 'payments' => [], 'obligations' => [], 'accounts' => [], 'registers' => []];
        foreach (['erp_establishment_id'] as $field) {
            if (isset($body[$field])) {
                app(ErpCatalogScope::class)->assertActiveEstablishment($companyId, $body[$field]);
            }
        }
        if (! empty($body['erp_financial_account_id'])) {
            $account = $read->resource($request, ErpFinancialAccount::class, $body['erp_financial_account_id'], 'erp_financial_account_id');
            if ($account->status !== 'active' || $account->currency !== $body['currency'] || ($account->erp_establishment_id !== null && $account->erp_establishment_id !== ($body['erp_establishment_id'] ?? null))) {
                throw ValidationException::withMessages(['erp_financial_account_id' => 'Conta inativa ou moeda incompatível.']);
            }
            $refs['accounts'][] = $account->id;
        }
        $common = ['erp_company_id' => $companyId, 'posted_by_user_id' => $request->user()->id, 'posted_at' => now(), 'source_type' => 'manual', 'source_id' => 0, 'source_operation_key' => $key];
        if ($operation === self::ACCOUNT || $operation === self::REGISTER) {
            $class = $this->sourceClass($operation);
            $model = $class::create($body + ['erp_company_id' => $companyId, 'status' => 'active']);
        } elseif ($operation === self::OBLIGATION) {
            $model = ErpObligation::create($body + $common + ['open_amount' => $body['amount'], 'status' => 'open']);
        } elseif ($operation === self::PAYMENT) {
            $session = null;
            if ($body['method'] === 'cash') {
                if ($body['erp_cash_session_id'] === null) {
                    throw ValidationException::withMessages(['erp_cash_session_id' => 'Selecione explicitamente um caixa aberto.']);
                }
                $session = $cash->session($request, $body['erp_cash_session_id']);
                if ($session->currency !== $body['currency'] || (isset($account) && $account->erp_establishment_id !== null && $account->erp_establishment_id !== $session->erp_establishment_id) || ($body['erp_establishment_id'] !== null && $body['erp_establishment_id'] !== $session->erp_establishment_id)) {
                    throw ValidationException::withMessages(['erp_cash_session_id' => 'Moeda ou estabelecimento incompatível.']);
                }
                $body['erp_establishment_id'] = $session->erp_establishment_id;
                $refs['sessions'][] = $session->id;
                $refs['cashier_sessions'][] = $session->id;
            } elseif ($body['erp_cash_session_id'] !== null) {
                throw ValidationException::withMessages(['erp_cash_session_id' => 'Vínculo de caixa exige método dinheiro.']);
            }
            $model = ErpPayment::create($body + $common + ['status' => 'posted', 'ledger_version' => 1]);
            if ($session !== null) {
                $cash->append($request, $session, $body['direction'], 'payment', $model->amount, 'Pagamento manual', $key, $model->id);
            }
        } elseif ($operation === self::OPEN) {
            $register = ErpCashRegister::where('erp_company_id', $companyId)->lockForUpdate()->findOrFail($body['erp_cash_register_id']);
            abort_unless($register->status === 'active', 422, 'Caixa inativo');
            app(ErpCatalogScope::class)->assertActiveEstablishment($companyId, $register->erp_establishment_id);
            abort_if(ErpCashSession::where('erp_cash_register_id', $register->id)->where('status', 'open')->exists(), 422, 'Caixa já aberto');
            $model = ErpCashSession::create($body + ['erp_company_id' => $companyId, 'erp_establishment_id' => $register->erp_establishment_id, 'operator_user_id' => $request->user()->id, 'status' => 'open', 'opened_by_user_id' => $request->user()->id, 'opened_at' => now()]);
            $refs['sessions'][] = $model->id;
            $refs['cashier_sessions'][] = $model->id;
            $refs['registers'][] = $register->id;
        } elseif ($operation === self::MOVEMENT) {
            $session = $cash->session($request, $id);
            if (($body['kind'] === 'deposit' && $body['direction'] !== 'in') || ($body['kind'] === 'withdrawal' && $body['direction'] !== 'out')) {
                throw ValidationException::withMessages(['direction' => 'Direção incompatível com o movimento manual.']);
            }
            $model = $cash->append($request, $session, $body['direction'], $body['kind'], $body['amount'], $body['reason'], $key);
            $refs['sessions'][] = $session->id;
            $refs['cashier_sessions'][] = $session->id;
        } elseif ($operation === self::CLOSE) {
            $model = $cash->session($request, $id);
            $totals = $cash->totals($model);
            $result = app(ErpCashSessionStateMachine::class)->close($model->status, $model->opening_amount, $totals['movements_net'], $body['declared_closing_amount']);
            $model->fill($result + ['close_operation_key' => $key, 'closed_by_user_id' => $request->user()->id, 'closed_at' => now()])->save();
            $refs['sessions'][] = $model->id;
            $refs['cashier_sessions'][] = $model->id;
        } elseif ($operation === self::ALLOCATION_REVERSE) {
            $original = $read->allocation($request, $id);
            $model = app(ErpFinanceParticipant::class)->reverseAllocation($companyId, $original->id, $body['amount'], $body['reason'], $key, $request->user()->id);
            $refs['payments'][] = $original->erp_payment_id;
            $refs['obligations'][] = $original->erp_obligation_id;
        } else {
            $model = $this->reversePayment($request, $id, $body, $key, $refs);
        }
        $data = $read->serialize($model, $request);
        if ($model instanceof ErpPaymentAllocation) {
            $obligation = $model->obligation()->firstOrFail();
            $data += ['obligation_open_amount' => $obligation->open_amount, 'obligation_status' => $obligation->status, 'payment_available_amount' => $read->serialize($model->payment()->firstOrFail())['available_amount']];
        }
        $refs['resource_id'] = in_array($operation, [self::MOVEMENT, self::ALLOCATION_REVERSE, self::PAYMENT_REVERSE], true) ? $id : $model->id;

        return ['status' => in_array($operation, [self::CLOSE, self::ALLOCATION_REVERSE, self::PAYMENT_REVERSE], true) ? 200 : 201, 'body' => ['data' => $data], 'resource_refs' => $refs];
    }

    private function reversePayment(Request $request, int $id, array $body, string $key, array &$refs): ErpPayment
    {
        $company = CurrentCompany::fromRequest($request)->company->id;
        $read = app(ErpFinanceReadService::class);
        $original = $read->resource($request, ErpPayment::class, $id);
        $target = null;
        $movement = null;
        if ($original->method === 'cash') {
            $movement = ErpCashMovement::where('erp_company_id', $company)->where('erp_payment_id', $id)->first();
            if (! $movement || $original->erp_cash_session_id === null) {
                throw ValidationException::withMessages(['erp_cash_session_id' => 'Pagamento histórico exige conciliação de caixa.']);
            }
            $originalSession = $read->resource($request, ErpCashSession::class, $original->erp_cash_session_id);
            $targetId = $body['erp_cash_session_id'] ?? ($originalSession->status === 'open' ? $originalSession->id : null);
            if ($targetId === null) {
                throw ValidationException::withMessages(['erp_cash_session_id' => 'Informe um caixa atualmente aberto para a compensação.']);
            }
            ErpCashSession::where('erp_company_id', $company)->whereIn('id', [$originalSession->id, $targetId])->orderBy('id')->lockForUpdate()->get();
            $target = app(ErpCashLedger::class)->session($request, $targetId);
            if ($target->currency !== $original->currency) {
                throw ValidationException::withMessages(['erp_cash_session_id' => 'Moeda de caixa incompatível.']);
            }
            $refs['sessions'] = [$originalSession->id, $target->id];
            $refs['cashier_sessions'] = [$target->id];
        } elseif ($body['erp_cash_session_id'] !== null) {
            throw ValidationException::withMessages(['erp_cash_session_id' => 'Pagamento não movimentou caixa.']);
        }
        $original = ErpPayment::where('erp_company_id', $company)->lockForUpdate()->findOrFail($id);
        abort_unless($original->reversal_of_id === null && $original->status === 'posted' && ErpDecimal::isPositive($original->amount), 422, 'Pagamento não reversível');
        abort_if(ErpPayment::where('erp_company_id', $company)->where('reversal_of_id', $id)->exists(), 422, 'Pagamento já compensado');
        if ($original->direction === null) {
            abort(response()->json(['code' => 'legacy_operation_requires_reconciliation', 'message' => 'Direção histórica não conciliada.'], 409));
        }
        $allocations = ErpPaymentAllocation::where('erp_payment_id', $id)->whereNull('reversal_of_id')->orderBy('erp_obligation_id')->orderBy('id')->get();
        ErpObligation::where('erp_company_id', $company)->whereIn('id', $allocations->pluck('erp_obligation_id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($allocations as $allocation) {
            if (ErpDecimal::isPositive($read->reversible($allocation))) {
                app(ErpFinanceParticipant::class)->reverseAllocation($company, $allocation->id, null, $body['reason'], $key.'-'.$allocation->id, $request->user()->id);
            }
        }
        $reversal = ErpPayment::create([
            'erp_company_id' => $company, 'erp_establishment_id' => $original->erp_establishment_id, 'erp_financial_account_id' => $original->erp_financial_account_id,
            'erp_cash_session_id' => $target?->id, 'amount' => ErpDecimal::sub('0', $original->amount), 'direction' => $original->direction,
            'currency' => $original->currency, 'method' => $original->method, 'status' => 'posted', 'reason' => $body['reason'],
            'reversal_of_id' => $id, 'source_type' => 'payment_reversal', 'source_id' => $id, 'source_operation_key' => $key,
            'posted_by_user_id' => $request->user()->id, 'posted_at' => now(), 'ledger_version' => 1,
        ]);
        if ($target !== null) {
            app(ErpCashLedger::class)->append($request, $target, $original->direction === 'in' ? 'out' : 'in', 'reversal', $original->amount, $body['reason'], $key, $reversal->id, $movement->id);
        }
        $refs['payments'] = [$original->id, $reversal->id];
        $refs['obligations'] = $allocations->pluck('erp_obligation_id')->unique()->all();

        return $reversal;
    }
}
