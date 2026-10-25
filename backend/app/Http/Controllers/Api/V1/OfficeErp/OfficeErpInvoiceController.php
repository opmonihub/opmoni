<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpInvoices;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfficeErp\AdjustInvoiceRequest;
use App\Http\Requests\OfficeErp\SaveInvoiceRequest;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpInvoiceController extends Controller
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpInvoices $invoices): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_status' => ['sometimes', Rule::in(['draft', 'issued', 'canceled'])],
            'contract_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
        ]);
        $ctx = $admission->context($request);

        return response()->json($invoices->index($ctx, $filters))->header('Cache-Control', 'no-store');
    }

    public function store(SaveInvoiceRequest $request, OfficeErpAdmission $admission, OfficeErpInvoices $invoices, ErpOperationJournal $journal): JsonResponse
    {
        $ctx = $admission->context($request);
        $data = $request->validated();
        $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($data['contract_id'])->firstOrFail();
        $invoice = $invoices->draft($ctx, $contract, $request->invoiceInput(), $journal->key($request, true));

        return response()->json(['data' => $invoices->commandData($invoice)], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $invoice, OfficeErpAdmission $admission, OfficeErpInvoices $invoices): JsonResponse
    {
        $ctx = $admission->context($request);

        return response()->json($invoices->show($ctx, $invoice))->header('Cache-Control', 'no-store');
    }

    public function update(SaveInvoiceRequest $request, int $invoice, OfficeErpAdmission $admission, OfficeErpInvoices $invoices, ErpOperationJournal $journal): JsonResponse
    {
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)->whereKey($invoice)->firstOrFail();
        $updated = $invoices->updateDraft($ctx, $record, $request->invoiceInput(), $journal->key($request, true));

        return response()->json(['data' => $invoices->commandData($updated)])->header('Cache-Control', 'no-store');
    }

    public function issue(Request $request, int $invoice, OfficeErpAdmission $admission, OfficeErpInvoices $invoices, ErpOperationJournal $journal): JsonResponse
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['invoice' => ['O comando de emissão não aceita conteúdo.']]);
        }
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)->whereKey($invoice)->firstOrFail();
        $issued = $invoices->issue($ctx, $record, $journal->key($request, true));

        return response()->json(['data' => $invoices->commandData($issued)])->header('Cache-Control', 'no-store');
    }

    public function adjust(AdjustInvoiceRequest $request, int $invoice, OfficeErpAdmission $admission, OfficeErpInvoices $invoices, ErpOperationJournal $journal): JsonResponse
    {
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)->whereKey($invoice)->firstOrFail();
        $adjustment = $invoices->adjust($ctx, $record, $request->validated(), $journal->key($request, true));

        return response()->json(['data' => [
            'id' => (int) $adjustment->id,
            'invoice_id' => (int) $adjustment->invoice_id,
            'direction' => (string) $adjustment->direction,
            'amount' => (string) $adjustment->amount,
            'reason' => (string) $adjustment->reason,
            'corrects_adjustment_id' => $adjustment->correction_of_id === null ? null : (int) $adjustment->correction_of_id,
            'posted_at' => $adjustment->posted_at?->toISOString(),
        ]], 201)->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request, int $invoice, OfficeErpAdmission $admission, OfficeErpInvoices $invoices, ErpOperationJournal $journal): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
        ]);
        if (array_diff(array_keys($request->all()), ['reason']) !== []) {
            throw ValidationException::withMessages(['invoice' => ['O comando de cancelamento contém campos inválidos.']]);
        }
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)->whereKey($invoice)->firstOrFail();
        $invoices->cancel($ctx, $record, $data['reason'], $journal->key($request, true));

        return response()->json($invoices->show($ctx, $invoice))->header('Cache-Control', 'no-store');
    }
}
