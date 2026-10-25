<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpReceipts;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfficeErp\StoreReceiptRequest;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OfficeErpReceiptController extends Controller
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpReceipts $receipts): JsonResponse
    {
        $filters = $request->validate([
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100'],
        ]);

        return response()->json($receipts->index($admission->context($request), $filters))
            ->header('Cache-Control', 'no-store');
    }

    public function store(StoreReceiptRequest $request, OfficeErpAdmission $admission, OfficeErpReceipts $receipts, ErpOperationJournal $journal): JsonResponse
    {
        $ctx = $admission->context($request);
        $receipt = $receipts->post($ctx, $request->validated(), $journal->key($request, true));

        return response()->json($receipts->commandOutcome($ctx, (int) $receipt->operation_id), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $receipt, OfficeErpAdmission $admission, OfficeErpReceipts $receipts): JsonResponse
    {
        return response()->json($receipts->show($admission->context($request), $receipt))
            ->header('Cache-Control', 'no-store');
    }

    public function reverse(Request $request, int $receipt, OfficeErpAdmission $admission, OfficeErpReceipts $receipts, ErpOperationJournal $journal): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
        ]);
        if (array_diff(array_keys($request->all()), ['reason']) !== []) {
            throw ValidationException::withMessages(['receipt' => ['O comando de reversão contém campos inválidos.']]);
        }

        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)->whereKey($receipt)->firstOrFail();
        $reversal = $receipts->reverse($ctx, $record, $data['reason'], $journal->key($request, true));

        return response()->json($receipts->commandOutcome($ctx, (int) $reversal->operation_id), 201)
            ->header('Cache-Control', 'no-store');
    }
}
