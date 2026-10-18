<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpOperationLookupController extends Controller
{
    public function __invoke(Request $request, ErpOperationJournal $journal): JsonResponse
    {
        return response()->json(['data' => $journal->lookup($request)]);
    }
}
