<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpCompanyInitializationReconciliation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCompanyInitializationController extends Controller
{
    public function preview(Request $request, ErpCompanyInitializationReconciliation $reconciliation): JsonResponse
    {
        return $reconciliation->preview($request);
    }

    public function apply(Request $request, ErpCompanyInitializationReconciliation $reconciliation, ErpCatalogWriteService $writes): JsonResponse
    {
        return $writes->run($request, fn (): JsonResponse => $reconciliation->apply($request));
    }
}
