<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpTrialLinks;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpTrialLinkController extends Controller
{
    public function show(Request $request, OfficeErpAdmission $admission, OfficeErpTrialLinks $links): JsonResponse
    {
        $ctx = $admission->context($request);

        return response()->json(['data' => $links->read($ctx)])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, OfficeErpAdmission $admission, ErpOperationJournal $journal, OfficeErpTrialLinks $links): JsonResponse
    {
        $validated = $request->validate([
            'erp_plan_id' => ['required', 'integer:strict', 'min:1'],
            'enabled' => ['required', 'boolean:strict'],
        ]);
        $outcome = $links->configure(
            $admission->context($request),
            ['erp_plan_id' => (int) $validated['erp_plan_id'], 'enabled' => $validated['enabled']],
            $journal->key($request, true),
        );

        return response()->json(['data' => $outcome])->header('Cache-Control', 'no-store');
    }

    public function rotate(Request $request, OfficeErpAdmission $admission, ErpOperationJournal $journal, OfficeErpTrialLinks $links): JsonResponse
    {
        $outcome = $links->rotate($admission->context($request), $journal->key($request, true));

        return response()->json(['data' => $outcome])->header('Cache-Control', 'no-store');
    }
}
