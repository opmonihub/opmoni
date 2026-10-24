<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCfopResolver;
use App\Erp\ErpCommercialGate;
use App\Erp\ErpOperationNatureRules;
use App\Erp\ErpProductTaxResolver;
use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductTaxProfile;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpOperationNaturePreviewController extends ErpOperationNatureController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request): JsonResponse {
            abort_unless(app(ErpCommercialGate::class)->moduleEnabled($context->company, 'add_sale'), 403, 'Módulo não habilitado no contrato');
            $data = $request->validate([
                'erp_product_id' => ['required', 'integer', 'min:1'],
                'erp_operation_nature_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'line' => ['sometimes', 'array:cfop'],
                'context' => ['sometimes', 'array'],
            ]);
            $product = ErpProduct::query()->where('erp_company_id', $context->company->id)->where('status', 'active')->find($data['erp_product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(['erp_product_id' => 'Produto ativo inválido para esta empresa.']);
            }
            $default = ErpCompanyProductTaxDefault::query()->where('erp_company_id', $context->company->id)->lockForUpdate()->first();
            $profileIds = array_values(array_unique(array_filter([$product->erp_product_tax_profile_id, $default?->erp_product_tax_profile_id], fn ($id): bool => $id !== null)));
            ErpProductTaxProfile::query()->where('erp_company_id', $context->company->id)->whereIn('id', $profileIds)->orderBy('id')->lockForUpdate()->get();
            $rules = app(ErpOperationNatureRules::class);
            $nature = isset($data['erp_operation_nature_id']) ? $rules->nature($context, $data['erp_operation_nature_id'], related: true, lock: true) : null;
            if ($nature !== null) {
                $nature->cfopChoices()->where('erp_company_id', $context->company->id)->orderBy('id')->lockForUpdate()->get();
                $nature->productExceptions()->where('erp_company_id', $context->company->id)->where('erp_product_id', $product->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            }
            $product = $rules->product($context, $product->id);
            $resolver = app(ErpCfopResolver::class);
            $operationContext = $resolver->operationContext($data['context'] ?? []);
            $tax = app(ErpProductTaxResolver::class)->resolve($context, $product, array_filter(['movement' => $operationContext['movement']], fn ($value): bool => $value !== null));
            $cfop = $resolver->resolve($context, $product, $nature, $operationContext, $resolver->explicitChoice($data['line'] ?? []));
            $result = ['version' => 'operational-tax-preview-v1', 'product_tax_resolution' => $tax, 'cfop_resolution' => $cfop];
            $result['fingerprint'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

            return response()->json(['data' => $result]);
        });
    }
}
