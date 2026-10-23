<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\ErpCatalogScope;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Http\Requests\Erp\Fiscal\StoreErpFiscalInvalidationRequest;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ErpFiscalInvalidationController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private ErpFiscalIssuanceService $issuance,
        private ErpFiscalPayloadBuilder $payloadBuilder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpFiscalInvalidation::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $invalidation): JsonResponse
    {
        $model = $this->findForCompany($request, ErpFiscalInvalidation::class, $invalidation);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(StoreErpFiscalInvalidationRequest $request): JsonResponse
    {
        CurrentCompany::fromRequest($request)->assertCanMutate();
        [$model, $created] = $this->issuance->invalidationDraft($request, $request->validated());

        return response()->json(['data' => $this->serialize($model)], $created ? 201 : 200);
    }

    public function submit(Request $request, int $invalidation): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalInvalidation::class, $invalidation);
        if ($model->status !== 'accepted') {
            $this->scope->assertActiveEstablishment($context->company->id, $model->erp_establishment_id);
        }

        try {
            $result = $this->issuance->submitInvalidation($model, $request->user()?->id, $request->all());
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFiscalInvalidation $model */
        $attempt = ErpFiscalAttempt::query()->where('erp_fiscal_invalidation_id', $model->id)->latest('id')->first();

        return [
            'id' => $model->id,
            'attempt' => $attempt?->only(['id', 'action', 'status', 'safe_result']),
            'erp_establishment_id' => $model->erp_establishment_id,
            'model' => $model->model,
            'year' => $attempt ? (app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt)['request']['source']['year'] ?? null) : null,
            'environment' => $model->environment,
            'series' => $model->series,
            'number_from' => $model->number_from,
            'number_to' => $model->number_to,
            'status' => $model->status,
            'idempotency_key' => $model->idempotency_key,
            'justification' => $model->justification,
            'protocol' => $model->protocol,
        ];
    }
}
