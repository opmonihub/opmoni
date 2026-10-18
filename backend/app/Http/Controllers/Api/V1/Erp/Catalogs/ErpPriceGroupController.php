<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogDecimal;
use App\Models\Erp\ErpPriceGroup;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpPriceGroupController extends ErpCatalogController
{
    protected function modelClass(): string
    {
        return ErpPriceGroup::class;
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpPriceGroup $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'is_default' => $model->is_default,
            'percentage_adjustment' => $this->decimalString($model->percentage_adjustment),
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validated($request);
            $this->assertNameAvailable($data['name'], (int) $context->company->id);
            if (! empty($data['is_default'])) {
                ErpPriceGroup::query()->where('erp_company_id', $context->company->id)->update(['is_default' => false]);
            }
            $row = ErpPriceGroup::query()->create([
                ...$data,
                'erp_company_id' => $context->company->id,
                'status' => 'active',
            ]);

            return response()->json(['data' => $this->serialize($row)], 201);
        });
    }

    public function update(Request $request, int $priceGroup): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $priceGroup): JsonResponse {
            $model = $this->findForCompany($request, $priceGroup);
            $data = $this->validated($request, partial: true);
            if (isset($data['name'])) {
                $this->assertNameAvailable($data['name'], (int) $context->company->id, $model);
            }
            if (! empty($data['is_default'])) {
                ErpPriceGroup::query()->where('erp_company_id', $context->company->id)->whereKeyNot($model->id)->update(['is_default' => false]);
            }
            $model->fill($data);
            $model->save();

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'percentage_adjustment' => ['sometimes', ...ErpCatalogDecimal::widthRule(6, 4, signed: true)],
        ]);
    }

    private function assertNameAvailable(string $name, int $companyId, ?ErpPriceGroup $current = null): void
    {
        $normalized = mb_strtolower($name);
        if ($current !== null && mb_strtolower($current->name) === $normalized) {
            return;
        }
        if (ErpPriceGroup::query()
            ->where('erp_company_id', $companyId)
            ->where('status', 'active')
            ->whereRaw('lower(name) = ?', [$normalized])
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->exists()) {
            throw ValidationException::withMessages(['name' => 'Este grupo de preços já existe nesta empresa.']);
        }
    }
}
