<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCategory;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpUnit;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpProductController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope) {}

    protected function modelClass(): string
    {
        return ErpProduct::class;
    }

    protected function searchableColumns(): array
    {
        return ['name', 'sku', 'barcode'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpProduct $model */
        return [
            'id' => $model->id,
            'sku' => $model->sku,
            'name' => $model->name,
            'kind' => $model->kind,
            'track_stock' => $model->track_stock,
            'erp_unit_id' => $model->erp_unit_id,
            'erp_brand_id' => $model->erp_brand_id,
            'erp_category_id' => $model->erp_category_id,
            'sale_price' => $this->decimalString($model->sale_price),
            'warranty_months' => $model->warranty_months,
            'warranty_description' => $model->warranty_description,
            'barcode' => $model->barcode,
            'status' => $model->status,
            'variants' => $model->relationLoaded('variants')
                ? $model->variants->map(fn (ErpProductVariant $v): array => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'name' => $v->name,
                    'sale_price' => $v->sale_price !== null ? $this->decimalString($v->sale_price) : null,
                    'barcode' => $v->barcode,
                    'status' => $v->status,
                ])->all()
                : [],
        ];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $model = ErpProduct::query()
            ->where('erp_company_id', $companyId)
            ->with('variants')
            ->whereKey($id)
            ->firstOrFail();

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $this->validated($request, $companyId);

        $product = DB::transaction(function () use ($data, $companyId): ErpProduct {
            $variants = $data['variants'] ?? [];
            unset($data['variants']);
            $product = ErpProduct::query()->create([
                ...$data,
                'erp_company_id' => $companyId,
                'status' => 'active',
            ]);
            foreach ($variants as $variant) {
                $product->variants()->create($variant);
            }

            return $product->load('variants');
        });

        return response()->json(['data' => $this->serialize($product)], 201);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $model = $this->findForCompany($request, $product);
        $data = $this->validated($request, $companyId, partial: true);

        DB::transaction(function () use ($model, $data): void {
            $variants = $data['variants'] ?? null;
            unset($data['variants']);
            if ($data !== []) {
                $model->fill($data);
                $model->save();
            }
            if (is_array($variants)) {
                foreach ($variants as $variant) {
                    if (isset($variant['id'])) {
                        $row = $model->variants()->whereKey($variant['id'])->first();
                        if ($row) {
                            $row->fill(collect($variant)->except('id')->all());
                            $row->save();
                        }
                    } else {
                        $model->variants()->create($variant);
                    }
                }
            }
        });

        return response()->json(['data' => $this->serialize($model->fresh('variants'))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false): array
    {
        $data = $request->validate([
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:goods,service'],
            'track_stock' => ['sometimes', 'boolean'],
            'erp_unit_id' => [$partial ? 'sometimes' : 'required', 'integer'],
            'erp_brand_id' => ['sometimes', 'nullable', 'integer'],
            'erp_category_id' => ['sometimes', 'nullable', 'integer'],
            'sale_price' => ['sometimes', 'nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'warranty_months' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'warranty_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sale_price' => ['sometimes', 'nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'variants.*.barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if (isset($data['erp_unit_id'])) {
            $this->scope->assertBelongsToCompany(ErpUnit::class, $companyId, (int) $data['erp_unit_id'], 'erp_unit_id');
        }
        if (array_key_exists('erp_brand_id', $data) && $data['erp_brand_id'] !== null) {
            $this->scope->assertBelongsToCompany(ErpBrand::class, $companyId, (int) $data['erp_brand_id'], 'erp_brand_id');
        }
        if (array_key_exists('erp_category_id', $data) && $data['erp_category_id'] !== null) {
            $this->scope->assertBelongsToCompany(ErpCategory::class, $companyId, (int) $data['erp_category_id'], 'erp_category_id');
        }

        if (isset($data['kind']) && $data['kind'] === 'service') {
            $data['track_stock'] = false;
        }

        return $data;
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $id);
        if ($model->status === 'archived') {
            throw ValidationException::withMessages(['status' => 'Produto já arquivado']);
        }
        $model->status = 'archived';
        $model->save();

        return response()->json(['data' => $this->serialize($model->fresh('variants'))]);
    }
}
