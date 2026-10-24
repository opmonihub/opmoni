<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\CfopReference\CfopReferenceUnavailable;
use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationNatureRules;
use App\Models\Erp\ErpOperationNature;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpOperationNatureController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope, private ErpOperationNatureRules $rules) {}

    protected function modelClass(): string
    {
        return ErpOperationNature::class;
    }

    protected function searchableColumns(): array
    {
        return ['cfop', 'description'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpOperationNature $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'cfop' => $model->cfop,
            'description' => $model->description,
            'movement' => $model->movement,
            'fiscal_metadata' => $model->fiscal_metadata,
            'status' => $model->status,
            'configuration_revision' => $model->configuration_revision,
            'cfop_reference_version' => $model->cfop_reference_version,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validated($request, $context->company->id);
            app(ErpEstablishmentAccess::class)->assertNullableCatalogRelated($context, $data['erp_establishment_id'] ?? null);
            $row = $this->rules->save($request, $context, $data);

            return response()->json(['data' => $this->serialize($row)], 201);
        });
    }

    public function update(Request $request, int $operationNature): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $operationNature): JsonResponse {
            $model = $this->rules->nature($context, $operationNature, lock: true);
            $data = $this->validated(
                $request,
                $context->company->id,
                partial: true,
                unchangedHistoricalEstablishmentId: $model->erp_establishment_id !== null ? (int) $model->erp_establishment_id : null,
            );
            if (array_key_exists('erp_establishment_id', $data)) {
                app(ErpEstablishmentAccess::class)->assertNullableCatalogRelated($context, $data['erp_establishment_id']);
            }
            $model = $this->rules->save($request, $context, $data, $model);

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $id): JsonResponse {
            $nature = $this->rules->nature($context, $id, lock: true);
            $this->rules->expectedRevision($nature, $request->all(), false);
            if ($nature->status !== 'archived') {
                $nature->status = 'archived';
                $this->rules->advance($request, $context, $nature, 'archived');
            }

            return response()->json(['data' => $this->serialize($nature)]);
        });
    }

    protected function write(Request $request, Closure $operation): JsonResponse
    {
        try {
            return $this->rules->run($request, $operation);
        } catch (CfopReferenceUnavailable) {
            return response()->json(['code' => 'cfop_reference_unavailable', 'message' => 'A consulta da referência de CFOP está indisponível. Tente novamente.'], 503);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false, ?int $unchangedHistoricalEstablishmentId = null): array
    {
        $data = $request->validate([
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
            'cfop' => [$partial ? 'sometimes' : 'required', 'string', 'max:8'],
            'description' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'movement' => ['sometimes', 'in:in,out'],
            'fiscal_metadata' => ['sometimes', 'nullable', 'array'],
            'allowed_cfops' => ['sometimes', 'array', 'min:1', 'max:100'],
            'allowed_cfops.*' => ['required', 'string', 'regex:/\A[123567][0-9]{3}\z/', 'distinct:strict'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id'] ?? null);
        $this->scope->assertActiveEstablishment(
            $companyId,
            $data['erp_establishment_id'] ?? null,
            unchangedHistoricalId: $unchangedHistoricalEstablishmentId,
        );

        return $data;
    }
}
