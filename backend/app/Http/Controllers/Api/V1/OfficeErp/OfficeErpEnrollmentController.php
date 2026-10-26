<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpCustomerEnrollment;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Services\CnpjLookupException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpEnrollmentController extends Controller
{
    public function plans(Request $request, OfficeErpAdmission $admission, OfficeErpCustomerEnrollment $enrollment): JsonResponse
    {
        return response()->json($enrollment->plans($admission->context($request)))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, OfficeErpAdmission $admission, ErpOperationJournal $journal, OfficeErpCustomerEnrollment $enrollment): JsonResponse
    {
        $ctx = $admission->context($request);
        $key = $journal->key($request, true);

        try {
            $outcome = $enrollment->enroll($ctx, $request->all(), $key);
        } catch (CnpjLookupException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $outcome], 201)->header('Cache-Control', 'no-store');
    }

    public function convertTrial(Request $request, int $contract, OfficeErpAdmission $admission, ErpOperationJournal $journal, OfficeErpCustomerEnrollment $enrollment): JsonResponse
    {
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contract)->firstOrFail();
        $outcome = $enrollment->convertTrial($ctx, $record, $request->all(), $journal->key($request, true));

        return response()->json(['data' => $outcome])->header('Cache-Control', 'no-store');
    }
}
