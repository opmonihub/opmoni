<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\Commercial\OfficeErpBalances;
use App\Erp\Commercial\OfficeErpMoney;
use App\Http\Controllers\Controller;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class OfficeErpCompanyInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $contract = $this->ownerContract($request);
        $invoices = OfficeErpInvoice::query()
            ->where('account_id', $contract->account_id)
            ->where('contract_id', $contract->id)
            ->whereIn('document_status', ['issued', 'canceled'])
            ->with(['contract', 'adjustments'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'data' => $invoices->getCollection()->map(fn (OfficeErpInvoice $invoice): array => $this->invoiceData($invoice))->values()->all(),
            'meta' => $this->paginationMeta($invoices),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $invoice): JsonResponse
    {
        $contract = $this->ownerContract($request);
        $record = OfficeErpInvoice::query()
            ->where('account_id', $contract->account_id)
            ->where('contract_id', $contract->id)
            ->whereIn('document_status', ['issued', 'canceled'])
            ->with(['contract', 'adjustments'])
            ->whereKey($invoice)
            ->firstOrFail();

        return response()->json(['data' => $this->invoiceData($record)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function ownerContract(Request $request): OfficeErpContract
    {
        $context = CurrentCompany::fromRequest($request);
        abort_unless(
            $context->accessKind === 'direct'
                && $context->membership?->roleEnum() === ErpCompanyRole::Owner,
            403,
        );

        $subscription = $context->company->subscription()->firstOrFail();
        abort_unless($subscription->office_erp_contract_id !== null, 404);

        $contract = OfficeErpContract::query()
            ->whereKey($subscription->office_erp_contract_id)
            ->where('erp_company_id', $context->company->id)
            ->whereIn('agreement_status', ['accepted', 'effective', 'canceled'])
            ->firstOrFail();

        $revisionExists = OfficeErpContractRevision::query()
            ->where('account_id', $contract->account_id)
            ->where('contract_id', $contract->id)
            ->whereKey($contract->current_revision_id)
            ->exists();
        abort_unless($revisionExists, 404);

        return $contract;
    }

    /** @return array<string, mixed> */
    private function invoiceData(OfficeErpInvoice $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'kind' => (string) $invoice->kind,
            'document_status' => (string) $invoice->document_status,
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at?->toISOString(),
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'due_on' => $invoice->due_on->toDateString(),
            'currency' => (string) $invoice->currency,
            'items' => array_values($invoice->items_snapshot ?? []),
            'original_total' => OfficeErpMoney::canonical((string) $invoice->getRawOriginal('original_total'), 'original_total'),
            'adjustments' => $invoice->adjustments->map(fn ($adjustment): array => [
                'direction' => (string) $adjustment->direction,
                'amount' => OfficeErpMoney::canonical((string) $adjustment->getRawOriginal('amount'), 'amount'),
                'reason' => (string) $adjustment->reason,
                'posted_at' => $adjustment->posted_at?->toISOString(),
            ])->values()->all(),
            'balance' => OfficeErpBalances::forInvoice($invoice),
        ];
    }

    /** @return array{current_page:int,last_page:int,per_page:int,total:int} */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
