<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpAssignments;
use App\Erp\Commercial\OfficeErpSubscriptionCompanies;
use App\Erp\ErpOperationJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpSubscriptionController
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpSubscriptionCompanies $subscriptions): JsonResponse
    {
        return response()->json($subscriptions->index($admission->context($request), $request->query()))
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpSubscriptionCompanies $subscriptions): JsonResponse
    {
        return response()->json($subscriptions->show($admission->context($request), $company))
            ->header('Cache-Control', 'no-store');
    }

    public function contracts(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpSubscriptionCompanies $subscriptions): JsonResponse
    {
        return response()->json($subscriptions->contracts($admission->context($request), $company, $request->query()))
            ->header('Cache-Control', 'no-store');
    }

    public function invoices(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpSubscriptionCompanies $subscriptions): JsonResponse
    {
        return response()->json($subscriptions->invoices($admission->context($request), $company, $request->query()))
            ->header('Cache-Control', 'no-store');
    }

    public function assign(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpAssignments $assignments, ErpOperationJournal $journal): JsonResponse
    {
        $ctx = $admission->context($request);
        $result = $assignments->assign($ctx, $company, $request->all(), $journal->key($request, true));

        return response()->json(['data' => $result], 201)->header('Cache-Control', 'no-store');
    }
}
