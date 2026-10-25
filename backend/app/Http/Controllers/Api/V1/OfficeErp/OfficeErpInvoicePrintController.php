<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpInvoices;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpInvoicePrintController extends Controller
{
    public function __invoke(Request $request, int $invoice, OfficeErpAdmission $admission, OfficeErpInvoices $invoices): JsonResponse
    {
        $data = $invoices->show($admission->context($request), $invoice)['data'];
        abort_unless($data['document_status'] === 'issued', 404);

        $seller = $data['seller_snapshot'];
        $billTo = $data['bill_to_snapshot'];

        return response()->json(['data' => [
            'id' => $data['id'],
            'number' => $data['number'],
            'document_status' => $data['document_status'],
            'issued_at' => $data['issued_at'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'due_on' => $data['due_on'],
            'seller_snapshot' => [
                'account' => ['name' => data_get($seller, 'account.name')],
            ],
            'bill_to_snapshot' => [
                'client' => isset($billTo['client'])
                    ? array_intersect_key($billTo['client'], array_flip(['name', 'tax_id']))
                    : null,
                'company' => isset($billTo['company']) && is_array($billTo['company'])
                    ? array_intersect_key($billTo['company'], array_flip(['name', 'cnpj']))
                    : null,
            ],
            'items_snapshot' => array_map(
                static fn (array $item): array => array_intersect_key($item, array_flip(['description', 'amount', 'discount'])),
                $data['items_snapshot'],
            ),
            'adjustments' => array_map(
                static fn (array $adjustment): array => array_intersect_key($adjustment, array_flip(['direction', 'amount', 'reason'])),
                $data['adjustments'],
            ),
            'balance' => $data['balance'],
        ]])->header('Cache-Control', 'no-store');
    }
}
