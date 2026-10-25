<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfficeErp\ListCommercialRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpSummaryController extends Controller
{
    public function __invoke(Request $request, OfficeErpAdmission $admission, OfficeErpQueries $queries): JsonResponse
    {
        $filters = ListCommercialRequest::validateFilters('summary', $request->query());

        return response()->json(['data' => $queries->summary($admission->context($request), $filters)])
            ->header('Cache-Control', 'no-store');
    }
}
