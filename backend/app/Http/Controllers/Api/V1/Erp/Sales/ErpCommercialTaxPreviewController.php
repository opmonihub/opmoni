<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\Sales\ErpCommercialTaxReview;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCommercialTaxPreviewController extends Controller
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $kind = match ((string) $request->route()?->getName()) {
            'erp.quotes.tax-refresh-preview' => 'quote', 'erp.orders.tax-refresh-preview' => 'order', 'erp.sales.tax-refresh-preview' => 'sale',
        };

        return response()->json(['data' => app(ErpCommercialTaxReview::class)->preview($request, $kind, $id)]);
    }
}
