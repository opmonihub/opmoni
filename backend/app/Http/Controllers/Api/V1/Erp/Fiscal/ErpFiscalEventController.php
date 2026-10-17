<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
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

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'erp_fiscal_document_id' => ['required', 'integer'],
            'event_type' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'justification' => ['sometimes', 'nullable', 'string'],
        ]);

        ErpFiscalDocument::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($data['erp_fiscal_document_id'])
            ->firstOrFail();

        $existing = ErpFiscalEvent::query()
            ->where('erp_fiscal_document_id', $data['erp_fiscal_document_id'])
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            return response()->json(['data' => $this->serialize($existing)]);
        }

        $event = ErpFiscalEvent::query()->create([
            'erp_fiscal_document_id' => $data['erp_fiscal_document_id'],
            'event_type' => $data['event_type'],
            'status' => 'queued',
            'idempotency_key' => $data['idempotency_key'],
            'justification' => $data['justification'] ?? null,
            'requested_by_user_id' => $userId,
        ]);

        return response()->json(['data' => $this->serialize($event)], 201);
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

        try {
            $result = $this->issuance->submitEvent($model, $document);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFiscalEvent $model */
        return [
            'id' => $model->id,
            'erp_fiscal_document_id' => $model->erp_fiscal_document_id,
            'event_type' => $model->event_type,
            'status' => $model->status,
            'idempotency_key' => $model->idempotency_key,
            'justification' => $model->justification,
            'protocol' => $model->protocol,
        ];
    }
}
