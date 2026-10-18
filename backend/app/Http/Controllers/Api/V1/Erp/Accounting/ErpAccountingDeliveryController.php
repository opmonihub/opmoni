<?php

namespace App\Http\Controllers\Api\V1\Erp\Accounting;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\Accounting\AccountingMappingFence;
use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\ErpReleaseGate;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Account;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        abort_unless($context->accessKind === 'direct' && $context->membership?->roleEnum() === ErpCompanyRole::Owner, 403);
        $data = $request->validate(['erp_fiscal_document_id' => ['required', 'integer', 'min:1']]);
        $document = $this->findForCompany($request, ErpFiscalDocument::class, $data['erp_fiscal_document_id']);
        abort_unless($document->status === 'authorized', 422);
        $delegation = ErpOfficeDelegation::query()->where('erp_company_id', $context->company->id)->where('status', 'active')->first();
        abort_unless($delegation && ErpAccountingMapping::query()->where('delegation_id', $delegation->id)->where('erp_establishment_id', $document->erp_establishment_id)->where('status', 'active')->exists(), 422);

        return DB::transaction(function () use ($request, $document, $delegation): JsonResponse {
            resolve(AccountingMappingFence::class)->acquire(Account::query()->whereKey($delegation->account_id));
            $intent = $this->handoff->ensureDeliveryIntent($document, (int) $request->user()->id);
            $created = $intent->wasRecentlyCreated;
            $result = $this->handoff->retry($intent);
            abort_if($created && ! in_array($result->status, ['pending', 'delivered'], true), 422);

            return response()->json(['data' => $this->serialize($result)], $created ? 201 : 200);
        });
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
            'delegation_id' => $delivery->delegation_id,
            'mapping_id' => $delivery->mapping_id,
            'mapping_revision' => $delivery->mapping_revision,
            'can_retry' => $delivery->attempts < ErpAccountingHandoffService::MAX_ATTEMPTS && $delivery->status !== 'delivered' && ($delivery->status !== 'blocked' || in_array($delivery->reason_code, ['client_mapping_missing', 'mapping_pending_approval', 'fiscal_document_not_authorized'], true)),
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
