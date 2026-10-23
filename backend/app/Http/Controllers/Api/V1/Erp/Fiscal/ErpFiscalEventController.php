<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Http\Requests\Erp\Fiscal\StoreErpFiscalEventRequest;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ErpFiscalEventController extends ErpOperationsController
{
    public function __construct(private ErpFiscalIssuanceService $issuance) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $query = ErpFiscalEvent::query()
            ->whereHas('document', fn ($inner) => $inner->where('erp_company_id', $companyId));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $event): JsonResponse
    {
        $companyId = $this->companyId($request);
        $model = ErpFiscalEvent::query()
            ->whereHas('document', fn ($inner) => $inner->where('erp_company_id', $companyId))
            ->whereKey($event)
            ->firstOrFail();
        app(ErpEstablishmentAccess::class)->assertResource(CurrentCompany::fromRequest($request), $model);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(StoreErpFiscalEventRequest $request): JsonResponse
    {
        CurrentCompany::fromRequest($request)->assertCanMutate();
        [$model, $created] = $this->issuance->eventDraft($request, $request->validated());

        return response()->json(['data' => $this->serialize($model)], $created ? 201 : 200);
    }

    public function submit(Request $request, int $event): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $model = ErpFiscalEvent::query()
            ->whereHas('document', fn ($inner) => $inner->where('erp_company_id', $companyId))
            ->whereKey($event)
            ->firstOrFail();
        $document = ErpFiscalDocument::query()->findOrFail($model->erp_fiscal_document_id);
        app(ErpEstablishmentAccess::class)->assertResource($context, $model);
        if ($model->status !== 'accepted') {
            app(ErpCatalogScope::class)->assertActiveEstablishment($companyId, $document->erp_establishment_id, 'erp_fiscal_document_id');
        }

        try {
            $result = $this->issuance->submitEvent($model, $document, $request->user()?->id, $request->all());
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFiscalEvent $model */
        $attempt = ErpFiscalAttempt::query()->where('erp_fiscal_event_id', $model->id)->latest('id')->first();

        return [
            'id' => $model->id,
            'attempt' => $attempt?->only(['id', 'action', 'status', 'safe_result']),
            'erp_fiscal_document_id' => $model->erp_fiscal_document_id,
            'event_type' => $model->event_type,
            'status' => $model->status,
            'idempotency_key' => $model->idempotency_key,
            'justification' => $model->justification,
            'protocol' => $model->protocol,
        ];
    }
}
