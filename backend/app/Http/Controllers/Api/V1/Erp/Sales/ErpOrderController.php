<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\ErpCatalogScope;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Sales\ErpCommercialDocumentStateMachine;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Sales\ErpOrder;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpOrderController extends ErpOperationsController
{
    public function __construct(private ErpCatalogScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpOrder::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $model = $this->findForCompany($request, ErpOrder::class, $order);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'erp_establishment_id' => ['required', 'integer'],
            'order_no' => ['required', 'string', 'max:64'],
            'total_amount' => ['required'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);

        $order = ErpOrder::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $data['erp_establishment_id'],
            'order_no' => $data['order_no'],
            'status' => ErpCommercialDocumentStateMachine::ORDER_CONFIRMED,
            'total_amount' => ErpDecimal::parse($data['total_amount']),
            'currency' => $data['currency'] ?? 'BRL',
        ]);

        return response()->json(['data' => $this->serialize($order)], 201);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpOrder $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_quote_id' => $model->erp_quote_id,
            'order_no' => $model->order_no,
            'status' => $model->status,
            'total_amount' => $this->decimalString($model->total_amount),
            'currency' => $model->currency,
        ];
    }
}
