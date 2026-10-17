<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Erp\Finance\ErpDecimal;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpObligationController extends ErpOperationsController
{
    public function __construct(private ErpCatalogScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpObligation::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $obligation): JsonResponse
    {
        $model = $this->findForCompany($request, ErpObligation::class, $obligation);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'direction' => ['required', 'in:receivable,payable'],
            'category' => ['required', 'string', 'max:32'],
            'amount' => ['required'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
            'erp_financial_account_id' => ['sometimes', 'nullable', 'integer'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id'] ?? null);
        $this->scope->assertBelongsToCompany(
            ErpFinancialAccount::class,
            $companyId,
            $data['erp_financial_account_id'] ?? null,
            'erp_financial_account_id',
        );

        $amount = ErpDecimal::parse($data['amount']);
        $operationKey = $data['source_operation_key'] ?? null;
        if ($operationKey !== null) {
            $existing = ErpObligation::query()
                ->where('erp_company_id', $companyId)
                ->where('source_operation_key', $operationKey)
                ->first();
            if ($existing) {
                return response()->json(['data' => $this->serialize($existing)]);
            }
        }

        $obligation = DB::transaction(function () use ($data, $companyId, $userId, $amount): ErpObligation {
            return ErpObligation::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $data['erp_establishment_id'] ?? null,
                'erp_financial_account_id' => $data['erp_financial_account_id'] ?? null,
                'direction' => $data['direction'],
                'category' => $data['category'],
                'amount' => $amount,
                'open_amount' => $amount,
                'currency' => $data['currency'] ?? 'BRL',
                'due_on' => $data['due_on'] ?? null,
                'status' => 'open',
                'source_type' => 'manual',
                'source_id' => 0,
                'source_operation_key' => $data['source_operation_key'] ?? null,
                'posted_by_user_id' => $userId,
                'posted_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->serialize($obligation)], 201);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpObligation $model */
        return [
            'id' => $model->id,
            'direction' => $model->direction,
            'category' => $model->category,
            'amount' => $this->decimalString($model->amount),
            'open_amount' => $this->decimalString($model->open_amount),
            'currency' => $model->currency,
            'due_on' => $model->due_on?->toDateString(),
            'status' => $model->status,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_financial_account_id' => $model->erp_financial_account_id,
            'source_operation_key' => $model->source_operation_key,
        ];
    }
}
