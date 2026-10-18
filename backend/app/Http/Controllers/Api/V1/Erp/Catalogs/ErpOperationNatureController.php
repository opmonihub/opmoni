<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Models\Erp\ErpOperationNature;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpOperationNatureController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope) {}

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
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request, $context->company->id);
        app(ErpEstablishmentAccess::class)->assertNullableCatalogRelated($context, $data['erp_establishment_id'] ?? null);
        $row = ErpOperationNature::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, int $operationNature): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $operationNature);
        $data = $this->validated($request, $context->company->id, partial: true);
        if (array_key_exists('erp_establishment_id', $data)) {
            app(ErpEstablishmentAccess::class)->assertNullableCatalogRelated($context, $data['erp_establishment_id']);
        }
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false): array
    {
        $data = $request->validate([
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
            'cfop' => [$partial ? 'sometimes' : 'required', 'string', 'max:8'],
            'description' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'movement' => ['sometimes', 'in:in,out'],
            'fiscal_metadata' => ['sometimes', 'nullable', 'array'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id'] ?? null);

        return $data;
    }
}
