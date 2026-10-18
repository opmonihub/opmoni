<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\ErpCatalogScope;
use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
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

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'erp_establishment_id' => ['required', 'integer'],
            'model' => ['required', 'in:55,65,57,58'],
            'environment' => ['sometimes', 'in:homologation,production'],
            'series' => ['required', 'integer', 'min:1'],
            'number_from' => ['required', 'integer', 'min:1'],
            'number_to' => ['required', 'integer', 'gte:number_from'],
            'justification' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);
        $validationErrors = $this->payloadBuilder->validateInvalidation([
            'model' => $data['model'],
            'series' => $data['series'],
            'number_from' => $data['number_from'],
            'number_to' => $data['number_to'],
            'justification' => $data['justification'],
        ]);
        if ($validationErrors !== []) {
            abort(response()->json(['code' => 'validation_error', 'errors' => $validationErrors], 422));
        }

        $existing = ErpFiscalInvalidation::query()
            ->where('erp_company_id', $companyId)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            return response()->json(['data' => $this->serialize($existing)]);
        }

        $invalidation = ErpFiscalInvalidation::query()->create([
            ...$data,
            'erp_company_id' => $companyId,
            'status' => 'queued',
            'environment' => $data['environment'] ?? 'homologation',
            'requested_by_user_id' => $userId,
        ]);

        return response()->json(['data' => $this->serialize($invalidation)], 201);
    }

    public function submit(Request $request, int $invalidation): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalInvalidation::class, $invalidation);

        try {
            $result = $this->issuance->submitInvalidation($model, $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFiscalInvalidation $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'model' => $model->model,
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
