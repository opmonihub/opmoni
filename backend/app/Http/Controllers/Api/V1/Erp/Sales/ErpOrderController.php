<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\ErpOperationJournal;
use App\Erp\Sales\ErpCommercialDraftService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpOrderController extends ErpOperationsController
{
    public function index(Request $request): JsonResponse
    {
        return $this->paginated($request, app(ErpCommercialDraftService::class)->scopedQuery($request, 'order'), fn (Model $row): array => app(ErpCommercialDraftService::class)->serialize($row, false));
    }

    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json(['data' => app(ErpCommercialDraftService::class)->serialize(app(ErpCommercialDraftService::class)->resource($request, 'order', $order))]);
    }

    public function store(Request $request): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeCommercial($request, 'erp.orders.store');
    }

    public function update(Request $request, int $order): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeCommercial($request, 'erp.orders.update', $order);
    }

    public function convert(Request $request, int $order): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeCommercial($request, 'erp.orders.convert', $order);
    }
}
