<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogWriteService;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpProductController extends ErpCatalogController
{
    public function __construct(private ErpCatalogWriteService $writes) {}

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
            'ncm' => $model->ncm,
            'cest' => $model->cest,
            'origin_code' => $model->origin_code,
            'icms_cst' => $model->icms_cst,
            'icms_csosn' => $model->icms_csosn,
            'pis_cst' => $model->pis_cst,
            'cofins_cst' => $model->cofins_cst,
            'ipi_cst' => $model->ipi_cst,
            'icms_rate' => $this->nullableDecimalString($model->icms_rate),
            'pis_rate' => $this->nullableDecimalString($model->pis_rate),
            'cofins_rate' => $this->nullableDecimalString($model->cofins_rate),
            'ipi_rate' => $this->nullableDecimalString($model->ipi_rate),
            'simple_national_credit_rate' => $this->nullableDecimalString($model->simple_national_credit_rate),
            'ibs_cbs_cst' => $model->ibs_cbs_cst,
            'ibs_cbs_classification_code' => $model->ibs_cbs_classification_code,
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
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $product = $this->writes->createProduct($context->company->id, $request->all());

            return response()->json(['data' => $this->serialize($product)], 201);
        });
    }

    public function update(Request $request, int $product): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $product): JsonResponse {
            $model = $this->findForCompany($request, $product);
            $model = $this->writes->updateProduct($context, $model, $request->all());

            return response()->json(['data' => $this->serialize($model)]);
        });
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->writes->run($request, function () use ($request, $id): JsonResponse {
            $model = $this->findForCompany($request, $id);
            if ($model->status === 'archived') {
                throw ValidationException::withMessages(['status' => 'Produto já arquivado']);
            }
            $model->status = 'archived';
            $model->save();

            return response()->json(['data' => $this->serialize($model->fresh('variants'))]);
        });
    }

    private function nullableDecimalString(mixed $value): ?string
    {
        return $value === null ? null : $this->decimalString($value);
    }
}
