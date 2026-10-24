<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpProductTaxProfile;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpCompanyProductTaxDefaults
{
    /** @return array{default_product_tax_profile_id: ?int, tax_default_revision: int} */
    public function toPayload(ErpCompany $company): array
    {
        $default = ErpCompanyProductTaxDefault::query()->where('erp_company_id', $company->id)->first();

        return ['default_product_tax_profile_id' => $default?->erp_product_tax_profile_id, 'tax_default_revision' => $default?->revision ?? 0];
    }

    /** @param array<string, mixed> $payload */
    public function update(Request $request, CurrentCompany $context, array $payload): void
    {
        app(ErpCatalogWriteService::class)->assertProtected($request, $context);
        $data = Validator::make($payload, [
            'default_product_tax_profile_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expected_tax_default_revision' => ['sometimes', 'integer', 'min:0'],
        ])->validate();
        if (! array_key_exists('default_product_tax_profile_id', $data)) {
            return;
        }
        $context->assertPermission('erp.company.settings.update', true);
        app(ErpCompanyAdministration::class)->mutate($request, false, function () use ($request, $context, $data) {
            $default = ErpCompanyProductTaxDefault::query()->where('erp_company_id', $context->company->id)->lockForUpdate()->first();
            if (isset($data['expected_tax_default_revision']) && (int) $data['expected_tax_default_revision'] !== ($default?->revision ?? 0)) {
                abort(response()->json(['code' => 'product_tax_default_changed', 'message' => 'O padrão tributário mudou. Revise a configuração novamente.'], 409));
            }
            $id = $data['default_product_tax_profile_id'] === null ? null : (int) $data['default_product_tax_profile_id'];
            if ($id !== null) {
                $profile = ErpProductTaxProfile::query()->where('erp_company_id', $context->company->id)->whereKey($id)->lockForUpdate()->first();
                if ($profile === null || $profile->status !== 'active' || $profile->current_revision === null) {
                    throw ValidationException::withMessages(['default_product_tax_profile_id' => 'Perfil tributário indisponível para esta empresa.']);
                }
            }
            if ($default !== null && $default->erp_product_tax_profile_id === $id) {
                return response()->json([]);
            }
            $created = $default === null;
            $default ??= new ErpCompanyProductTaxDefault(['erp_company_id' => $context->company->id]);
            $default->fill(['erp_product_tax_profile_id' => $id, 'revision' => ($default->revision ?? 0) + 1])->save();
            app(ErpCatalogWriteService::class)->record($request, $context, $default, $created ? 'created' : 'updated');

            return response()->json([]);
        });
    }
}
