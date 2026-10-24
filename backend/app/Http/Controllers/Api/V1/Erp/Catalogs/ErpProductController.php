<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpProductTaxIntent;
use App\Erp\ErpProductTaxResolver;
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

    protected function indexRelations(): array
    {
        return ['variants'];
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
            'barcode_type' => $model->barcode_type,
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
            'erp_product_tax_profile_id' => $model->erp_product_tax_profile_id,
            'tax_field_intents' => $model->tax_field_intents,
            'status' => $model->status,
            'variants' => $model->relationLoaded('variants')
                ? $model->variants->map(fn (ErpProductVariant $v): array => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'name' => $v->name,
                    'sale_price' => $v->sale_price !== null ? $this->decimalString($v->sale_price) : null,
                    'barcode' => $v->barcode,
                    'barcode_type' => $v->barcode_type,
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
            $product = $this->writes->createProduct($request, $context, $request->all());

            return response()->json(['data' => $this->serialize($product)], 201);
        });
    }

    public function taxPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'kind' => ['sometimes', 'string', 'in:goods,service'],
            'context' => ['sometimes', 'array:tax_regime,movement'],
            'context.tax_regime' => ['sometimes', 'string', 'in:1,2,3,4'],
            'context.movement' => ['sometimes', 'string', 'in:in,out'],
        ]);
        $context = CurrentCompany::fromRequest($request);
        $current = isset($data['product_id']) ? ErpProduct::query()->where('erp_company_id', $context->company->id)->findOrFail($data['product_id']) : null;
        $candidate = $current === null ? new ErpProduct(['erp_company_id' => $context->company->id, 'kind' => 'goods']) : clone $current;
        $candidate->fill(app(ErpProductTaxIntent::class)->prepare($request->all(), $context->company->id, $current));
        if (isset($data['kind'])) {
            $candidate->kind = $data['kind'];
        }
        $resolver = app(ErpProductTaxResolver::class);
        $resolution = $resolver->resolve($context, $candidate, $data['context'] ?? []);
        if ($current !== null) {
            try {
                $resolution['previous'] = $resolver->resolve($context, $current, $data['context'] ?? []);
            } catch (ValidationException $error) {
                $resolution['previous'] = ['errors' => $error->errors()];
            }
        }

        return response()->json(['data' => $resolution]);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $product): JsonResponse {
            $model = $this->findForCompany($request, $product);
            $model = $this->writes->updateProduct($request, $context, $model, $request->all());

            return response()->json(['data' => $this->serialize($model)]);
        });
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $id): JsonResponse {
            $model = $this->findForCompany($request, $id);
            if ($model->status === 'archived') {
                throw ValidationException::withMessages(['status' => 'Produto já arquivado']);
            }
            $model->status = 'archived';
            $model->save();
            $this->writes->record($request, $context, $model, 'archived');

            return response()->json(['data' => $this->serialize($model->fresh('variants'))]);
        });
    }

    private function nullableDecimalString(mixed $value): ?string
    {
        return $value === null ? null : $this->decimalString($value);
    }
}
