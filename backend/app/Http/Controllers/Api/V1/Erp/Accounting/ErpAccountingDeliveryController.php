<?php

namespace App\Http\Controllers\Api\V1\Erp\Accounting;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\ErpReleaseGate;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpAccountingDeliveryController extends ErpOperationsController
{
    public function __construct(
        private ErpAccountingHandoffService $handoff,
        private ErpReleaseGate $releaseGate,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        $query = ErpAccountingDelivery::query()
            ->where('erp_company_id', $this->companyId($request))
            ->with(['erpFiscalDocument']);

        return $this->paginated($request, $query, fn (ErpAccountingDelivery $row): array => $this->serialize($row));
    }

    public function retry(Request $request, int $delivery): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        CurrentCompany::fromRequest($request)->assertCanMutate();
        $model = $this->findForCompany($request, ErpAccountingDelivery::class, $delivery);
        $result = $this->handoff->retry($model);

        return response()->json(['data' => $this->serialize($result)]);
    }

    /** @return array<string, mixed> */
    private function serialize(ErpAccountingDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'erp_fiscal_document_id' => $delivery->erp_fiscal_document_id,
            'account_id' => $delivery->account_id,
            'client_id' => $delivery->client_id,
            'fiscal_document_id' => $delivery->fiscal_document_id,
            'status' => $delivery->status,
            'reason_code' => $delivery->reason_code,
            'attempts' => $delivery->attempts,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'erp_fiscal_document' => $delivery->relationLoaded('erpFiscalDocument') && $delivery->erpFiscalDocument
                ? [
                    'id' => $delivery->erpFiscalDocument->id,
                    'access_key' => $delivery->erpFiscalDocument->access_key,
                    'status' => $delivery->erpFiscalDocument->status,
                ]
                : null,
        ];
    }
}
