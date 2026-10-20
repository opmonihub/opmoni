<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\ErpCommercialReturnService;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpSaleReturnController extends ErpOperationsController
{
    public function index(Request $request): JsonResponse
    {
        return $this->paginated($request, app(ErpCommercialReturnService::class)->scopedQuery($request, 'sale_return'), fn (Model $row): array => app(ErpCommercialReturnService::class)->serialize($row, false));
    }

    public function show(Request $request, int $saleReturn): JsonResponse
    {
        return response()->json(['data' => app(ErpCommercialReturnService::class)->serialize(app(ErpCommercialReturnService::class)->resource($request, 'sale_return', $saleReturn))]);
    }

    public function store(Request $request): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeCommercial($request, 'erp.sale-returns.store');
    }

    public function post(Request $request, int $saleReturn): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeCommercial($request, 'erp.sale-returns.post', $saleReturn);
    }
}
