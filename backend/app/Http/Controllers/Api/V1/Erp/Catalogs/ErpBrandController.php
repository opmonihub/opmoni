<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Models\Erp\ErpBrand;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpBrandController extends ErpCatalogController
{
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
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $brand = ErpBrand::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($brand)], 201);
    }

    public function update(Request $request, int $brand): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $brand);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255']]);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }
}
