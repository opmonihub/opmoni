<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\Sales\ErpCommercialDraftService;
use App\Erp\Sales\ErpSaleRepresentation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ErpSaleRepresentationController extends Controller
{
    public function __invoke(Request $request, int $sale, ErpCommercialDraftService $drafts, ErpSaleRepresentation $representation): Response
    {
        $model = $drafts->resource($request, 'sale', $sale);

        return response()->view('erp.sales.representation', $representation->viewData($model), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="venda-'.$model->id.'.html"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
        ]);
    }
}
