<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Finance\ErpCashSessionStateMachine;
use App\Erp\Finance\ErpDecimal;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ErpCashSessionController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private ErpCashSessionStateMachine $stateMachine,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpCashSession::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $cashSession): JsonResponse
    {
        $model = $this->findForCompany($request, ErpCashSession::class, $cashSession);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        abort_unless($userId !== null, 401);
        $data = $request->validate([
            'erp_cash_register_id' => ['required', 'integer'],
            'opening_amount' => ['sometimes'],
        ]);

        $register = ErpCashRegister::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($data['erp_cash_register_id'])
            ->firstOrFail();
        app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $register, 'erp_cash_register_id');
        abort_unless($register->status === 'active', 422, 'Caixa inativo');
        $this->scope->assertActiveEstablishment($companyId, $register->erp_establishment_id);

        $session = DB::transaction(function () use ($register, $companyId, $userId, $data): ErpCashSession {
            return ErpCashSession::query()->create([
                'erp_cash_register_id' => $register->id,
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $register->erp_establishment_id,
                'operator_user_id' => $userId,
                'opening_amount' => ErpDecimal::parse($data['opening_amount'] ?? '0'),
                'status' => ErpCashSessionStateMachine::STATUS_OPEN,
                'opened_by_user_id' => $userId,
                'opened_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->serialize($session)], 201);
    }

    public function close(Request $request, int $cashSession): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $userId = $request->user()?->id;
        $model = $this->findForCompany($request, ErpCashSession::class, $cashSession);
        abort_unless((int) $model->operator_user_id === (int) $userId || $context->membership?->role === 'owner', 403, 'Caixa pertence a outro operador');
        $data = $request->validate([
            'declared_closing_amount' => ['required'],
            'movements_net' => ['prohibited'],
            'close_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        if ($model->status === ErpCashSessionStateMachine::STATUS_CLOSED
            && ($data['close_operation_key'] ?? null) !== null
            && $model->close_operation_key === $data['close_operation_key']) {
            return response()->json(['data' => $this->serialize($model)]);
        }

        $this->scope->assertActiveEstablishment($context->company->id, $model->erp_establishment_id);

        try {
            $result = $this->stateMachine->close(
                $model->status,
                $this->decimalString($model->opening_amount),
                ErpDecimal::zero(),
                ErpDecimal::parse($data['declared_closing_amount']),
            );
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        $model->fill([
            'status' => $result['status'],
            'expected_closing_amount' => $result['expected_closing_amount'],
            'declared_closing_amount' => $result['declared_closing_amount'],
            'closing_difference' => $result['closing_difference'],
            'close_operation_key' => $data['close_operation_key'] ?? $model->close_operation_key,
            'closed_by_user_id' => $userId,
            'closed_at' => now(),
        ]);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpCashSession $model */
        return [
            'id' => $model->id,
            'erp_cash_register_id' => $model->erp_cash_register_id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'operator_user_id' => $model->operator_user_id,
            'opening_amount' => $this->decimalString($model->opening_amount),
            'expected_closing_amount' => $model->expected_closing_amount !== null
                ? $this->decimalString($model->expected_closing_amount)
                : null,
            'declared_closing_amount' => $model->declared_closing_amount !== null
                ? $this->decimalString($model->declared_closing_amount)
                : null,
            'closing_difference' => $model->closing_difference !== null
                ? $this->decimalString($model->closing_difference)
                : null,
            'status' => $model->status,
            'close_operation_key' => $model->close_operation_key,
            'opened_at' => $model->opened_at?->toIso8601String(),
            'closed_at' => $model->closed_at?->toIso8601String(),
        ];
    }
}
