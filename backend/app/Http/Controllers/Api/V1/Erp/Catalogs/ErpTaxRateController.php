<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogDecimal;
use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Models\Erp\ErpTaxRate;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpTaxRateController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope) {}

    protected function modelClass(): string
    {
        return ErpTaxRate::class;
    }

    protected function searchableColumns(): array
    {
        return ['name', 'tax_kind'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpTaxRate $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'name' => $model->name,
            'rate' => $this->decimalString($model->rate),
            'tax_kind' => $model->tax_kind,
            'metadata' => $model->metadata,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validated($request, $context->company->id);
            app(ErpEstablishmentAccess::class)->assertNullableCatalogRelated($context, $data['erp_establishment_id'] ?? null);
            $row = ErpTaxRate::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

            return response()->json(['data' => $this->serialize($row)], 201);
        });
    }

    public function update(Request $request, int $taxRate): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $taxRate): JsonResponse {
            $model = $this->findForCompany($request, $taxRate);
            $data = $this->validated(
                $request,
                $context->company->id,
                partial: true,
                unchangedHistoricalEstablishmentId: $model->erp_establishment_id !== null ? (int) $model->erp_establishment_id : null,
            );
            if (array_key_exists('erp_establishment_id', $data)) {
                app(ErpEstablishmentAccess::class)->assertNullableCatalogRelated($context, $data['erp_establishment_id']);
            }
            $model->fill($data);
            $model->save();

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false, ?int $unchangedHistoricalEstablishmentId = null): array
    {
        $data = $request->validate([
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'rate' => [$partial ? 'sometimes' : 'required', ...ErpCatalogDecimal::widthRule(6, 4)],
            'tax_kind' => ['sometimes', 'string', 'max:32'],
            'metadata' => ['sometimes', 'nullable', 'array'],
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
