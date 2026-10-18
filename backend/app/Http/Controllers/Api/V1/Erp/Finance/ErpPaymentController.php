<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpDecimal;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpPayment;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ErpPaymentController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpPayment::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        $model = $this->findForCompany($request, ErpPayment::class, $payment);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'amount' => ['required'],
            'method' => ['required', 'string', 'max:32'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
            'erp_financial_account_id' => ['sometimes', 'nullable', 'integer'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $data = $this->normalizeIdentifiers($data);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id'] ?? null);
        app(ErpEstablishmentAccess::class)->assertNullableRelated($context, $data['erp_establishment_id'] ?? null);
        $this->scope->assertBelongsToCompany(
            ErpFinancialAccount::class,
            $companyId,
            $data['erp_financial_account_id'] ?? null,
            'erp_financial_account_id',
        );
        if (isset($data['erp_financial_account_id'])) {
            $account = ErpFinancialAccount::query()->where('erp_company_id', $companyId)->findOrFail($data['erp_financial_account_id']);
            app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $account, 'erp_financial_account_id');
        }

        $amount = ErpDecimal::parse($data['amount']);
        abort_unless(ErpDecimal::isPositive($amount), 422, 'Valor deve ser positivo');
        $operationKey = $data['source_operation_key'] ?? null;
        if ($operationKey !== null) {
            $existing = ErpPayment::query()
                ->where('erp_company_id', $companyId)
                ->where('source_operation_key', $operationKey)
                ->first();
            if ($existing) {
                abort_unless(ErpDecimal::compare((string) $existing->amount, $amount) === 0 && $existing->method === $data['method'] && $existing->currency === ($data['currency'] ?? 'BRL') && $existing->erp_establishment_id === ($data['erp_establishment_id'] ?? null) && $existing->erp_financial_account_id === ($data['erp_financial_account_id'] ?? null), 409, 'Chave de operação reutilizada com conteúdo diferente');
                app(ErpEstablishmentAccess::class)->assertResource($context, $existing);

                return response()->json(['data' => $this->serialize($existing)]);
            }
        }

        $this->scope->assertActiveEstablishment($companyId, $data['erp_establishment_id'] ?? null);

        $payment = ErpPayment::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $data['erp_establishment_id'] ?? null,
            'erp_financial_account_id' => $data['erp_financial_account_id'] ?? null,
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'BRL',
            'method' => $data['method'],
            'status' => 'posted',
            'source_type' => 'manual',
            'source_id' => 0,
            'source_operation_key' => $data['source_operation_key'] ?? null,
            'posted_by_user_id' => $userId,
            'posted_at' => now(),
        ]);

        return response()->json(['data' => $this->serialize($payment)], 201);
    }

    public function allocate(Request $request, int|string $payment): JsonResponse
    {
        try {
            $result = app(ErpOperationJournal::class)->execute($request, $payment);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json($result['body'], $result['status']);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpPayment $model */
        return [
            'id' => $model->id,
            'amount' => $this->decimalString($model->amount),
            'currency' => $model->currency,
            'method' => $model->method,
            'status' => $model->status,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_financial_account_id' => $model->erp_financial_account_id,
            'source_operation_key' => $model->source_operation_key,
        ];
    }
}
