<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Models\Erp\ErpCategory;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCategoryController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope) {}

    protected function modelClass(): string
    {
        return ErpCategory::class;
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpCategory $model */
        return ['id' => $model->id, 'parent_id' => $model->parent_id, 'name' => $model->name, 'status' => $model->status];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request, $context->company->id);
        $row = ErpCategory::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $category);
        $data = $this->validated($request, $context->company->id, partial: true);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        if (! empty($data['parent_id'])) {
            $this->scope->assertBelongsToCompany(ErpCategory::class, $companyId, (int) $data['parent_id'], 'parent_id');
        }

        return $data;
    }
}
