<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCompanyOperatingSettings;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpProduct;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpCatalogPrintController extends Controller
{
    public function __construct(private ErpCompanyOperatingSettings $settings) {}

    public function showSettings(Request $request): JsonResponse
    {
        $company = CurrentCompany::fromRequest($request)->company;
        $settings = $company->operating_settings ?? [];

        return response()->json([
            'data' => $settings['catalog_print'] ?? [
                'label_width_mm' => 50,
                'label_height_mm' => 30,
                'show_price' => true,
                'show_barcode' => true,
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $request->validate([
            'label_width_mm' => ['required', 'integer', 'min:20', 'max:200'],
            'label_height_mm' => ['required', 'integer', 'min:10', 'max:200'],
            'show_price' => ['required', 'boolean'],
            'show_barcode' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($context, $data): void {
            /** @var ErpCompany $company */
            $company = ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);
            $this->settings->mergeOperating($company, ['catalog_print' => $data]);
            $company->save();
        });

        return $this->showSettings($request);
    }

    public function productLabels(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $ids = $request->validate(['product_ids' => ['required', 'array', 'max:100'], 'product_ids.*' => ['integer']])['product_ids'];
        $products = ErpProduct::query()
            ->where('erp_company_id', $context->company->id)
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->get(['id', 'name', 'sku', 'barcode', 'sale_price']);

        return response()->json([
            'data' => $products->map(fn (ErpProduct $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'sale_price' => (string) $p->sale_price,
            ])->all(),
        ]);
    }
}
