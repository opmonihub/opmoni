<?php

namespace App\Http\Controllers\Api\V1\Erp\Accounting;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\Accounting\AccountingMappingFence;
use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\ErpOperationJournal;
use App\Erp\ErpReleaseGate;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Account;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingDeliveryDispatch;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ErpAccountingDeliveryController extends ErpOperationsController
{
    public function __construct(
        private ErpAccountingHandoffService $handoff,
        private ErpReleaseGate $releaseGate,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        $request->validate(['status' => ['sometimes', 'nullable', 'string', Rule::in(['pending', 'delivered', 'failed', 'blocked'])]]);
        $query = ErpAccountingDelivery::query()
            ->where('erp_company_id', $this->companyId($request))
            ->with(['erpFiscalDocument'])
            ->addSelect(['latest_dispatch_status' => ErpAccountingDeliveryDispatch::query()
                ->select('status')
                ->whereColumn('erp_accounting_delivery_id', 'erp_accounting_deliveries.id')
                ->orderByDesc('generation')
                ->limit(1)]);

        return $this->paginated($request, $query, fn (ErpAccountingDelivery $row): array => $this->serialize($row));
    }

    public function retry(Request $request, int $delivery): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        CurrentCompany::fromRequest($request)->assertCanMutate();
        $journal = app(ErpOperationJournal::class);
        $model = $this->findForCompany($request, ErpAccountingDelivery::class, $delivery);

        return $journal->executeAccountingRetry($request, $delivery, function () use ($model): JsonResponse {
            $result = $this->handoff->retry($model);

            return response()->json(['data' => $this->serialize($this->withLatestDispatchStatus($result))]);
        });
    }

    public function show(Request $request, int $delivery): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        $model = $this->findForCompany($request, ErpAccountingDelivery::class, $delivery);
        $this->withLatestDispatchStatus($model);
        $model->loadMissing('erpFiscalDocument');
        $mapping = $model->mapping_id === null ? null : ErpAccountingMapping::query()
            ->whereKey($model->mapping_id)
            ->where('erp_company_id', $model->erp_company_id)
            ->where('account_id', $model->account_id)
            ->where('delegation_id', $model->delegation_id)
            ->first();

        return response()->json(['data' => $this->serialize($model) + [
            'approved_mapping' => $mapping === null ? null : [
                'id' => $mapping->id,
                'revision' => $mapping->revision,
                'erp_establishment_id' => $mapping->erp_establishment_id,
                'status' => $mapping->status,
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        abort_unless($context->accessKind === 'direct' && $context->membership?->roleEnum() === ErpCompanyRole::Owner, 403);
        $data = $request->validate(['erp_fiscal_document_id' => ['required', 'integer', 'min:1'], 'erp_fiscal_event_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $document = $this->findForCompany($request, ErpFiscalDocument::class, $data['erp_fiscal_document_id']);
        $event = isset($data['erp_fiscal_event_id']) ? ErpFiscalEvent::query()->whereKey($data['erp_fiscal_event_id'])->where('erp_fiscal_document_id', $document->id)->first() : null;
        abort_if(isset($data['erp_fiscal_event_id']) && $event === null, 422);
        $artifacts = app(ErpFiscalArtifactStore::class);
        abort_unless(($event === null ? $document->status === 'authorized' : in_array($document->status, ['authorized', 'canceled'], true))
            && $artifacts->verifiedAuthorizedXmlReady($document)
            && ($event === null || $artifacts->verifiedAcceptedEvent($event, $document) !== null), 422);
        $delegation = ErpOfficeDelegation::query()->where('erp_company_id', $context->company->id)->where('status', 'active')->first();
        abort_unless($delegation && ErpAccountingMapping::query()->where('delegation_id', $delegation->id)->where('erp_establishment_id', $document->erp_establishment_id)->where('status', 'active')->exists(), 422);

        return DB::transaction(function () use ($request, $document, $delegation, $event): JsonResponse {
            resolve(AccountingMappingFence::class)->acquire(Account::query()->whereKey($delegation->account_id));
            $intent = $event === null ? $this->handoff->ensureDeliveryIntent($document, (int) $request->user()->id)
                : $this->handoff->ensureEventDeliveryIntent($document, $event, (int) $request->user()->id);
            $created = $intent->wasRecentlyCreated;
            $result = $created ? $this->handoff->retry($intent) : $intent->fresh(['erpFiscalDocument']);
            abort_if($created && ! in_array($result->status, ['pending', 'delivered'], true), 422);

            return response()->json(['data' => $this->serialize($this->withLatestDispatchStatus($result))], $created ? 201 : 200);
        });
    }

    private function withLatestDispatchStatus(ErpAccountingDelivery $delivery): ErpAccountingDelivery
    {
        if (! array_key_exists('latest_dispatch_status', $delivery->getAttributes())) {
            $delivery->setAttribute('latest_dispatch_status', ErpAccountingDeliveryDispatch::query()
                ->where('erp_accounting_delivery_id', $delivery->id)
                ->orderByDesc('generation')
                ->value('status'));
        }

        return $delivery;
    }

    /** @return array<string, mixed> */
    private function serialize(ErpAccountingDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'erp_fiscal_document_id' => $delivery->erp_fiscal_document_id,
            'projection_identity' => $delivery->projection_identity,
            'erp_fiscal_event_id' => $delivery->erp_fiscal_event_id,
            'account_id' => $delivery->account_id,
            'client_id' => $delivery->client_id,
            'fiscal_document_id' => $delivery->fiscal_document_id,
            'delegation_id' => $delivery->delegation_id,
            'mapping_id' => $delivery->mapping_id,
            'mapping_revision' => $delivery->mapping_revision,
            'can_retry' => $delivery->attempts < ErpAccountingHandoffService::MAX_ATTEMPTS
                && $delivery->status !== 'delivered'
                && in_array($delivery->getAttribute('latest_dispatch_status'), [null, 'completed'], true)
                && (in_array($delivery->status, ['pending', 'failed'], true)
                    || ($delivery->status === 'blocked' && in_array($delivery->reason_code, ['client_mapping_missing', 'mapping_pending_approval', 'fiscal_document_not_authorized', 'fiscal_authorization_unverified'], true))),
            'status' => $delivery->status,
            'reason_code' => $delivery->reason_code,
            'attempts' => $delivery->attempts,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'erp_fiscal_document' => $delivery->relationLoaded('erpFiscalDocument') && $delivery->erpFiscalDocument
                ? [
                    'id' => $delivery->erpFiscalDocument->id,
                    'model' => $delivery->erpFiscalDocument->model,
                    'erp_establishment_id' => $delivery->erpFiscalDocument->erp_establishment_id,
                    'access_key' => $delivery->erpFiscalDocument->access_key,
                    'status' => $delivery->erpFiscalDocument->status,
                ]
                : null,
        ];
    }
}
