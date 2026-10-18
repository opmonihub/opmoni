<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogDecimal;
use App\Erp\ErpCatalogScope;
use App\Erp\ErpCatalogWriteService;
use App\Models\Erp\ErpUnit;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpUnitController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope, private ErpCatalogWriteService $writes) {}

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
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $companyId = $context->company->id;
            $data = $this->validated($request, $companyId);
            $unit = ErpUnit::query()->create([...$data, 'erp_company_id' => $companyId, 'status' => 'active']);

            return response()->json(['data' => $this->serialize($unit)], 201);
        });
    }

    public function update(Request $request, int $unit): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $unit): JsonResponse {
            $model = $this->findForCompany($request, $unit);
            $data = $this->validated($request, $context->company->id, partial: true, current: $model);
            $model->fill($data);
            $model->save();

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false, ?ErpUnit $current = null): array
    {
        $data = $request->validate([
            'short_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:16'],
            'actual_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:64'],
            'allow_decimal' => ['sometimes', 'boolean'],
            'base_unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'conversion_factor' => ['sometimes', 'nullable', ...ErpCatalogDecimal::widthRule(12, 8)],
        ]);
        $shortName = $data['short_name'] ?? $current?->short_name;
        if (is_string($shortName) && ErpUnit::query()
            ->where('erp_company_id', $companyId)
            ->where('short_name', $shortName)
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->exists()) {
            throw ValidationException::withMessages(['short_name' => 'Esta sigla já está em uso nesta empresa.']);
        }

        $baseUnitId = array_key_exists('base_unit_id', $data) ? $data['base_unit_id'] : $current?->base_unit_id;
        $factor = array_key_exists('conversion_factor', $data) ? $data['conversion_factor'] : $current?->conversion_factor;

        if ($baseUnitId === null) {
            if ($factor !== null) {
                throw ValidationException::withMessages(['conversion_factor' => 'Informe uma unidade base para o fator de conversão']);
            }
            if (array_key_exists('base_unit_id', $data)) {
                $data['conversion_factor'] = null;
            }

            return $data;
        }

        $baseUnitId = (int) $baseUnitId;
        $this->scope->assertActiveReference(
            ErpUnit::class,
            $companyId,
            $baseUnitId,
            'base_unit_id',
            $current !== null && (int) $current->base_unit_id === $baseUnitId ? $baseUnitId : null,
        );
        if ($current !== null && $current->id === $baseUnitId) {
            throw ValidationException::withMessages(['base_unit_id' => 'Uma unidade não pode ser base de si mesma']);
        }
        if ($factor === null || $factor === '') {
            throw ValidationException::withMessages(['conversion_factor' => 'Informe o fator de conversão']);
        }
        ErpCatalogDecimal::assertPositive('conversion_factor', $factor);
        if ($current !== null && $this->wouldCreateCycle($current->id, $baseUnitId)) {
            throw ValidationException::withMessages(['base_unit_id' => 'A conversão criaria um ciclo entre unidades']);
        }
        if (array_key_exists('conversion_factor', $data)) {
            $data['conversion_factor'] = (string) $data['conversion_factor'];
        }

        return $data;
    }

    private function wouldCreateCycle(int $unitId, int $baseUnitId): bool
    {
        $visited = [];
        $cursor = $baseUnitId;
        while ($cursor > 0) {
            if ($cursor === $unitId) {
                return true;
            }
            if (isset($visited[$cursor])) {
                return true;
            }
            $visited[$cursor] = true;
            $cursor = (int) (ErpUnit::query()->whereKey($cursor)->value('base_unit_id') ?? 0);
        }

        return false;
    }
}
