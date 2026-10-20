<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpOperationJournal;
use App\Erp\Stock\ErpPurchaseImportService;
use App\Erp\Stock\ErpPurchaseOperation;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Support\AccessSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpPurchaseXmlPreviewController extends ErpOperationsController
{
    public function store(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $data = app(ErpPurchaseImportService::class)->preview($request);
            AccessSession::assertOwnership($request);

            return response()->json(['data' => $data]);
        });
    }

    public function read(Request $request): JsonResponse
    {
        $service = app(ErpPurchaseImportService::class);
        $preview = $service->find($request);

        return response()->json(['data' => $service->serialize($request, $preview, $request->input('token'))]);
    }

    public function confirm(Request $request): JsonResponse
    {
        return app(ErpOperationJournal::class)->executePurchase($request, ErpPurchaseOperation::CONFIRM);
    }
}
