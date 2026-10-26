<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpBalances;
use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\Commercial\OfficeErpMoney;
use App\Erp\Commercial\OfficeErpQueries;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OfficeErpContractController extends Controller
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpContracts $contracts, OfficeErpQueries $queries): JsonResponse
    {
        $filters = ListCommercialRequest::validateFilters('contracts', $request->query());
        $ctx = $admission->context($request);
        $page = $queries->contracts($ctx, $filters);
        $contractIds = array_map(static fn (OfficeErpContract $contract): int => (int) $contract->id, $page->items());
        $invoicesByContract = $contractIds === []
            ? collect()
            : OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
                ->whereIn('contract_id', $contractIds)
                ->with(['adjustments', 'allocations.receipt.reversal'])
                ->orderBy('id')
                ->get()
                ->groupBy('contract_id');

        return response()->json([
            'data' => array_map(function (OfficeErpContract $contract) use ($contracts, $invoicesByContract): array {
                $invoices = $invoicesByContract->get($contract->id, collect());
                $outstanding = '0.00';
                foreach ($invoices as $invoice) {
                    $outstanding = OfficeErpMoney::add($outstanding, OfficeErpBalances::forInvoice($invoice)['outstanding']);
                }
                $subscription = $contract->company?->subscription;

                return [
                    'id' => (int) $contract->id,
                    'client' => ['id' => (int) $contract->client_id, 'name' => (string) $contract->client?->name],
                    'company' => $contract->company === null ? null : [
                        'id' => (int) $contract->company->id,
                        'name' => (string) $contract->company->name,
                        'owner_setup_status' => (string) $contract->company->owner_setup_status,
                    ],
                    'agreement_status' => (string) $contract->agreement_status,
                    ...$contracts->linkedSubscriptionSummary($contract, $subscription),
                    'current_revision' => $contract->currentRevision === null ? null : $contracts->revisionData($contract->currentRevision),
                    'pending_revision' => $contract->pendingRevision === null ? null : $contracts->revisionData($contract->pendingRevision),
                    'service_anchor_on' => $contract->service_anchor_on?->toDateString(),
                    'billing_timezone' => (string) $contract->billing_timezone,
                    'next_period_index' => $contract->next_period_index,
                    'next_period_month_offset' => $contract->next_period_month_offset,
                    'cancel_effective_on' => $contract->cancel_effective_on?->toDateString(),
                    'cancellation_reason' => $contract->cancellation_reason,
                    'manual_hold' => (bool) $contract->manual_hold,
                    'balance' => ['outstanding' => $outstanding, 'invoice_count' => $invoices->count()],
                ];
            }, $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'no-store');
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
