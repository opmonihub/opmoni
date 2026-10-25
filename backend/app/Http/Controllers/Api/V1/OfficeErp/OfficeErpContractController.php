<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use App\Models\Erp\Commercial\OfficeErpContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpContractController extends Controller
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpContracts $contracts): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'agreement_status' => ['sometimes', Rule::in(['accepted', 'effective', 'canceled'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($contracts->index($admission->context($request), $filters))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $contract, OfficeErpAdmission $admission, OfficeErpContracts $contracts): JsonResponse
    {
        return response()->json($contracts->show($admission->context($request), $contract))->header('Cache-Control', 'no-store');
    }

    public function activate(Request $request, int $contract, OfficeErpAdmission $admission, OfficeErpContracts $contracts, ErpOperationJournal $journal): JsonResponse
    {
        $this->assertEmptyBody($request);
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contract)->firstOrFail();
        $result = $contracts->activate($ctx, $record, $journal->key($request, true));

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function amend(Request $request, int $contract, OfficeErpAdmission $admission, OfficeErpContracts $contracts, ErpOperationJournal $journal): JsonResponse
    {
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contract)->firstOrFail();
        $key = $journal->key($request, true);
        $contracts->amend($ctx, $record, $request->all(), $key);

        return response()->json($contracts->lookup($ctx, $key))->header('Cache-Control', 'no-store');
    }

    public function renew(Request $request, int $contract, OfficeErpAdmission $admission, OfficeErpContracts $contracts, ErpOperationJournal $journal): JsonResponse
    {
        $this->assertEmptyBody($request);
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contract)->firstOrFail();
        $key = $journal->key($request, true);
        $contracts->renew($ctx, $record, $key);

        return response()->json($contracts->lookup($ctx, $key))->header('Cache-Control', 'no-store');
    }

    public function suspend(Request $request, int $contract, OfficeErpAdmission $admission, OfficeErpContracts $contracts, ErpOperationJournal $journal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u']]);
        $this->assertOnlyFields($request, ['reason']);
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contract)->firstOrFail();
        $key = $journal->key($request, true);
        $contracts->suspend($ctx, $record, $data['reason'], $key);

        return response()->json($contracts->lookup($ctx, $key))->header('Cache-Control', 'no-store');
    }

    public function resume(Request $request, int $contract, OfficeErpAdmission $admission, OfficeErpContracts $contracts, ErpOperationJournal $journal): JsonResponse
    {
        $this->assertEmptyBody($request);
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contract)->firstOrFail();
        $key = $journal->key($request, true);
        $contracts->resume($ctx, $record, $key);

        return response()->json($contracts->lookup($ctx, $key))->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request, int $contract, OfficeErpAdmission $admission, OfficeErpContracts $contracts, ErpOperationJournal $journal): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['immediate', 'period_end'])],
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
        ]);
        $this->assertOnlyFields($request, ['mode', 'reason']);
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($contract)->firstOrFail();
        $key = $journal->key($request, true);
        $contracts->cancel($ctx, $record, $data['mode'], $data['reason'], $key);

        return response()->json($contracts->lookup($ctx, $key))->header('Cache-Control', 'no-store');
    }

    private function assertEmptyBody(Request $request): void
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['contract' => ['O comando não aceita conteúdo.']]);
        }
    }

    /** @param list<string> $fields */
    private function assertOnlyFields(Request $request, array $fields): void
    {
        if (array_diff(array_keys($request->all()), $fields) !== []) {
            throw ValidationException::withMessages(['contract' => ['O comando contém campos inválidos.']]);
        }
    }
}
