<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpCatalogWriteService;
use App\Models\Erp\ErpCategory;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpCategoryController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope, private ErpCatalogWriteService $writes) {}

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
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validated($request, $context->company->id);
            $row = ErpCategory::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

            return response()->json(['data' => $this->serialize($row)], 201);
        });
    }

    public function update(Request $request, int $category): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $category): JsonResponse {
            $model = $this->findForCompany($request, $category);
            $data = $this->validated($request, $context->company->id, partial: true, current: $model);
            $model->fill($data);
            $model->save();

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false, ?ErpCategory $current = null): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parentId = (int) $data['parent_id'];
            $this->scope->assertActiveReference(
                ErpCategory::class,
                $companyId,
                $parentId,
                'parent_id',
                $current !== null && (int) $current->parent_id === $parentId ? $parentId : null,
            );
            if ($current !== null && $this->wouldCreateCycle($current->id, $parentId)) {
                throw ValidationException::withMessages(['parent_id' => 'Uma categoria não pode ser descendente de si mesma.']);
            }
        }

        if (isset($data['name'])) {
            $normalized = mb_strtolower($data['name']);
            $sameName = $current !== null && mb_strtolower($current->name) === $normalized;
            if (! $sameName && ErpCategory::query()
                ->where('erp_company_id', $companyId)
                ->where('status', 'active')
                ->whereRaw('lower(name) = ?', [$normalized])
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->exists()) {
                throw ValidationException::withMessages(['name' => 'Esta categoria já existe nesta empresa.']);
            }
        }

        return $data;
    }

    private function wouldCreateCycle(int $categoryId, int $parentId): bool
    {
        $visited = [];
        $cursor = $parentId;
        while ($cursor > 0) {
            if ($cursor === $categoryId) {
                return true;
            }
            if (isset($visited[$cursor])) {
                return true;
            }
            $visited[$cursor] = true;
            $cursor = (int) (ErpCategory::query()->whereKey($cursor)->value('parent_id') ?? 0);
        }

        return false;
    }
}
