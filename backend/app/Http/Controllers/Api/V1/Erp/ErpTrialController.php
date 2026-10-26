<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\Commercial\OfficeErpTrialLinks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ErpTrialController extends Controller
{
    public function show(Request $request, string $code, OfficeErpTrialLinks $links): JsonResponse
    {
        return response()->json(['data' => $links->resolvePublic($code)])
            ->header('Cache-Control', 'no-store');
    }
}
