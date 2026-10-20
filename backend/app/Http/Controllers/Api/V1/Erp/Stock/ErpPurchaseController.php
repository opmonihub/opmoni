<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpOperationJournal;
use App\Erp\Stock\ErpPurchaseDraftService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Stock\ErpPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpPurchaseController extends ErpOperationsController
{
    public function index(Request $request): JsonResponse
    {
        $query = ErpPurchase::where('erp_company_id', $this->companyId($request))->with('lines');

        return $this->paginated($request, $query, fn ($purchase) => app(ErpPurchaseDraftService::class)->serialize($purchase, false));
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        $service = app(ErpPurchaseDraftService::class);

        return response()->json(['data' => $service->serialize($service->resource($request, $purchase))]);
    }

    public function store(Request $request): JsonResponse
    {
        return app(ErpOperationJournal::class)->executePurchase($request, 'erp.purchases.store');
    }

    public function update(Request $request, int $purchase): JsonResponse
    {
        return app(ErpOperationJournal::class)->executePurchase($request, 'erp.purchases.update', $purchase);
    }

    public function post(Request $request, int $purchase): JsonResponse
    {
        return app(ErpOperationJournal::class)->executePurchase($request, 'erp.purchases.post', $purchase);
    }
}
