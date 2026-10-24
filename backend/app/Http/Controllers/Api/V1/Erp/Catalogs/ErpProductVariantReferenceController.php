<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Http\Controllers\Controller;
use App\Models\Erp\ErpProductVariant;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ErpProductVariantReferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'usage' => ['required', 'string', 'in:sales,purchase'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $companyId = CurrentCompany::fromRequest($request)->company->id;

        $query = ErpProductVariant::query()
            ->with('product:id,erp_company_id,name,sku,kind,track_stock,erp_unit_id,status')
            ->where('status', 'active')
            ->whereHas('product', function (Builder $product) use ($companyId, $params): void {
                $product->where('erp_company_id', $companyId)->where('status', 'active');
                if ($params['usage'] === 'purchase') {
                    $product->where('kind', 'goods')->where('track_stock', true);
                }
            })
            ->orderBy('id');

        $search = trim((string) ($params['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function (Builder $matches) use ($needle): void {
                $matches
                    ->whereRaw('erp_product_variants.name LIKE ? ESCAPE ?', [$needle, '\\'])
                    ->orWhereRaw('erp_product_variants.sku LIKE ? ESCAPE ?', [$needle, '\\'])
                    ->orWhereHas('product', function (Builder $product) use ($needle): void {
                        $product
                            ->whereRaw('name LIKE ? ESCAPE ?', [$needle, '\\'])
                            ->orWhereRaw('sku LIKE ? ESCAPE ?', [$needle, '\\'])
                            ->orWhereRaw('barcode LIKE ? ESCAPE ?', [$needle, '\\']);
                    });
            });
        }

        $page = $query->paginate((int) ($params['per_page'] ?? 15));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ErpProductVariant $variant): array => $this->serialize($variant))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $variant): JsonResponse
    {
        $companyId = CurrentCompany::fromRequest($request)->company->id;
        $record = ErpProductVariant::query()
            ->with('product:id,erp_company_id,name,sku,kind,track_stock,erp_unit_id,status')
            ->whereKey($variant)
            ->whereHas('product', fn (Builder $product) => $product->where('erp_company_id', $companyId))
            ->firstOrFail();

        return response()->json(['data' => $this->serialize($record)]);
    }

    /** @return array<string, mixed> */
    private function serialize(ErpProductVariant $variant): array
    {
        $product = $variant->product;

        return [
            'id' => $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'status' => $variant->status,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'kind' => $product->kind,
                'track_stock' => $product->track_stock,
                'erp_unit_id' => $product->erp_unit_id,
                'status' => $product->status,
            ],
        ];
    }
}
