<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\Finance\ErpDecimal;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpPurchaseReturnController extends ErpOperationsController
{
    public function index(Request $request): JsonResponse
    {
        $query = ErpPurchaseReturn::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $purchaseReturn): JsonResponse
    {
        $model = $this->findForCompany($request, ErpPurchaseReturn::class, $purchaseReturn);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'erp_purchase_id' => ['required', 'integer'],
            'total_amount' => ['required'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $purchase = ErpPurchase::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($data['erp_purchase_id'])
            ->firstOrFail();

        abort_unless($purchase->status === 'posted', 422, 'Compra precisa estar lançada');

        $return = ErpPurchaseReturn::query()->create([
            'erp_purchase_id' => $purchase->id,
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $purchase->erp_establishment_id,
            'status' => 'draft',
            'total_amount' => ErpDecimal::parse($data['total_amount']),
            'source_operation_key' => $data['source_operation_key'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($return)], 201);
    }

    public function post(Request $request, int $purchaseReturn): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $userId = $request->user()?->id;
        $model = $this->findForCompany($request, ErpPurchaseReturn::class, $purchaseReturn);

        if ($model->status === 'posted') {
            return response()->json(['data' => $this->serialize($model)]);
        }
        abort_unless($model->status === 'draft', 422, 'Devolução não está em rascunho');

        DB::transaction(function () use ($model, $userId): void {
            $model->status = 'posted';
            $model->posted_by_user_id = $userId;
            $model->posted_at = now();
            $model->save();
        });

        return response()->json(['data' => $this->serialize($model->fresh())]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpPurchaseReturn $model */
        return [
            'id' => $model->id,
            'erp_purchase_id' => $model->erp_purchase_id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'status' => $model->status,
            'total_amount' => $this->decimalString($model->total_amount),
            'source_operation_key' => $model->source_operation_key,
        ];
    }
}
