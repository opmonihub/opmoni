<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogWriteService;
use App\Models\Erp\ErpBrand;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpBrandController extends ErpCatalogController
{
    public function __construct(private ErpCatalogWriteService $writes) {}

    protected function modelClass(): string
    {
        return ErpBrand::class;
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpBrand $model */
        return ['id' => $model->id, 'name' => $model->name, 'status' => $model->status];
    }

    public function store(Request $request): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validated($request, $context->company->id);
            $brand = ErpBrand::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);
            $this->record($request, $context, $brand, 'created');

            return response()->json(['data' => $this->serialize($brand)], 201);
        });
    }

    public function update(Request $request, int $brand): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $brand): JsonResponse {
            $model = $this->findForCompany($request, $brand);
            $data = $this->validated($request, $context->company->id, partial: true, current: $model);
            $model->fill($data);
            $model->save();
            $this->record($request, $context, $model, 'updated');

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    /** @return array{name: string} */
    private function validated(Request $request, int $companyId, bool $partial = false, ?ErpBrand $current = null): array
    {
        $data = $request->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255']]);
        if (isset($data['name']) && ($current === null || mb_strtolower($current->name) !== mb_strtolower($data['name']))) {
            if (ErpBrand::query()
                ->where('erp_company_id', $companyId)
                ->where('status', 'active')
                ->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->exists()) {
                throw ValidationException::withMessages(['name' => 'Esta marca já existe nesta empresa.']);
            }
        }

        return $data;
    }
}
