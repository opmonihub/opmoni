<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpCommercialReturnService;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpPurchaseReturnController extends ErpOperationsController
{
    public function index(Request $request): JsonResponse
    {
        return $this->paginated($request, app(ErpCommercialReturnService::class)->scopedQuery($request, 'purchase_return'), fn (Model $row): array => app(ErpCommercialReturnService::class)->serialize($row, false));
    }

    public function show(Request $request, int $purchaseReturn): JsonResponse
    {
        return response()->json(['data' => app(ErpCommercialReturnService::class)->serialize(app(ErpCommercialReturnService::class)->resource($request, 'purchase_return', $purchaseReturn))]);
    }

    public function store(Request $request): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeCommercial($request, 'erp.purchase-returns.store');
    }

    public function post(Request $request, int $purchaseReturn): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeCommercial($request, 'erp.purchase-returns.post', $purchaseReturn);
    }
}
