<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Models\Erp\ErpUnit;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpUnitController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope) {}

    protected function modelClass(): string
    {
        return ErpUnit::class;
    }

    protected function searchableColumns(): array
    {
        return ['short_name', 'actual_name'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpUnit $model */
        return [
            'id' => $model->id,
            'short_name' => $model->short_name,
            'actual_name' => $model->actual_name,
            'allow_decimal' => $model->allow_decimal,
            'base_unit_id' => $model->base_unit_id,
            'conversion_factor' => $model->conversion_factor !== null ? $this->decimalString($model->conversion_factor) : null,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request, $context->company->id);
        $unit = ErpUnit::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($unit)], 201);
    }

    public function update(Request $request, int $unit): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $unit);
        $data = $this->validated($request, $context->company->id, partial: true);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false): array
    {
        $data = $request->validate([
            'short_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:16'],
            'actual_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:64'],
            'allow_decimal' => ['sometimes', 'boolean'],
            'base_unit_id' => ['sometimes', 'nullable', 'integer'],
            'conversion_factor' => ['sometimes', 'nullable', 'regex:/^\d+(\.\d{1,8})?$/'],
        ]);

        if (! empty($data['base_unit_id'])) {
            $this->scope->assertBelongsToCompany(ErpUnit::class, $companyId, (int) $data['base_unit_id'], 'base_unit_id');
            if (empty($data['conversion_factor'])) {
                throw ValidationException::withMessages(['conversion_factor' => 'Informe o fator de conversão']);
            }
        } elseif (array_key_exists('base_unit_id', $data) && $data['base_unit_id'] === null) {
            $data['conversion_factor'] = null;
        }

        return $data;
    }
}
