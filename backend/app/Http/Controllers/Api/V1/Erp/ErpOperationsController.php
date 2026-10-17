<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\Finance\ErpDecimal;
use App\Http\Controllers\Controller;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class ErpOperationsController extends Controller
{
    protected function companyId(Request $request): int
    {
        return CurrentCompany::fromRequest($request)->company->id;
    }

    /** @param  class-string<Model>  $modelClass */
    protected function findForCompany(Request $request, string $modelClass, int $id, ?string $companyColumn = 'erp_company_id'): Model
    {
        return $modelClass::query()
            ->where($companyColumn, $this->companyId($request))
            ->whereKey($id)
            ->firstOrFail();
    }

    protected function decimalString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return ErpDecimal::zero();
        }

        return ErpDecimal::parse(is_string($value) ? $value : (string) $value);
    }

    /** @return array{per_page: int, status: ?string} */
    protected function listParams(Request $request): array
    {
        $status = $request->query('status');

        return [
            'per_page' => min(100, max(1, (int) $request->query('per_page', 15))),
            'status' => is_string($status) && $status !== '' ? $status : null,
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  callable(Model): array<string, mixed>  $serialize
     */
    protected function paginated(Request $request, Builder $query, callable $serialize): JsonResponse
    {
        $params = $this->listParams($request);
        if ($params['status'] !== null && $query->getModel()->isFillable('status')) {
            $query->where('status', $params['status']);
        }

        /** @var LengthAwarePaginator<int, Model> $page */
        $page = $query->orderByDesc('id')->paginate($params['per_page']);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Model $row): array => $serialize($row))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
