<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Enums\Erp\ErpEstablishmentResource;
use App\Erp\ErpCatalogScope;
use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpEstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ErpCatalogController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return list<string> */
    abstract protected function searchableColumns(): array;

    abstract protected function serialize(Model $model): array;

    /** @return list<string> */
    protected function indexRelations(): array
    {
        return [];
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $params = app(ErpCatalogScope::class)->listParams($request);

        /** @var Builder<Model> $query */
        $query = $this->modelClass()::query()
            ->with($this->indexRelations())
            ->where('erp_company_id', $companyId)
            ->orderBy('id');
        $resource = ErpEstablishmentResource::forModel($this->modelClass());
        if ($resource !== null) {
            app(ErpEstablishmentAccess::class)->apply($query, CurrentCompany::fromRequest($request), $resource);
        }

        if ($params['status'] !== null) {
            $query->where('status', $params['status']);
        }

        if ($params['search'] !== null && $params['search'] !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $params['search']).'%';
            $query->where(function (Builder $inner) use ($needle): void {
                foreach ($this->searchableColumns() as $column) {
                    $inner->orWhere($column, 'like', $needle);
                }
            });
        }

        $page = $query->paginate($params['per_page']);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Model $row): array => $this->serialize($row))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $model = $this->findForCompany($request, $id);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return app(ErpCatalogWriteService::class)->run($request, function (CurrentCompany $context) use ($request, $id): JsonResponse {
            $model = $this->findForCompany($request, $id);
            $model->status = 'archived';
            $model->save();
            $this->record($request, $context, $model, 'archived');

            return response()->json(['data' => $this->serialize($model)]);
        });
    }

    protected function companyId(Request $request): int
    {
        return CurrentCompany::fromRequest($request)->company->id;
    }

    protected function findForCompany(Request $request, int $id): Model
    {
        $companyId = $this->companyId($request);

        $query = $this->modelClass()::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($id);
        $resource = ErpEstablishmentResource::forModel($this->modelClass());
        if ($resource !== null) {
            app(ErpEstablishmentAccess::class)->apply($query, CurrentCompany::fromRequest($request), $resource);
        }

        return $query->firstOrFail();
    }

    protected function decimalString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return is_string($value) ? $value : (string) $value;
    }

    /** @param Closure(CurrentCompany): JsonResponse $operation */
    protected function write(Request $request, Closure $operation): JsonResponse
    {
        return app(ErpCatalogWriteService::class)->run($request, $operation);
    }

    protected function record(Request $request, CurrentCompany $context, Model $resource, string $action): void
    {
        app(ErpCatalogWriteService::class)->record($request, $context, $resource, $action);
    }
}
