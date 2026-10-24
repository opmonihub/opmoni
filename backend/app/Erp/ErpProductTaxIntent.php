<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductTaxProfile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpProductTaxIntent
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function prepare(array $payload, int $companyId, ?ErpProduct $current = null): array
    {
        $data = Validator::make($payload, [
            ...ErpProductTaxFields::rules(),
            'erp_product_tax_profile_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tax_field_intents' => ['sometimes', 'array'],
            'tax_field_intents.*' => ['required', 'string', 'in:value,inherit,unset'],
        ])->validate();
        $submitted = $data['tax_field_intents'] ?? [];
        foreach (array_diff(array_keys($submitted), ErpProductTaxFields::names()) as $field) {
            throw ValidationException::withMessages(['tax_field_intents.'.$field => 'Campo tributário não permitido.']);
        }
        if (isset($data['erp_product_tax_profile_id'])) {
            $profile = ErpProductTaxProfile::query()->where('erp_company_id', $companyId)->whereKey($data['erp_product_tax_profile_id'])->where('status', 'active')->first();
            if ($profile === null || $profile->current_revision === null) {
                throw ValidationException::withMessages(['erp_product_tax_profile_id' => 'Perfil tributário indisponível para esta empresa.']);
            }
            $settings = ErpCompany::query()->findOrFail($companyId)->operating_settings ?? [];
            $known = ['kind' => $payload['kind'] ?? $current?->kind ?? 'goods', 'tax_regime' => isset($settings['regime']) ? (string) $settings['regime'] : null];
            foreach ($profile->currentRevision?->applicability ?? [] as $key => $required) {
                if (isset($known[$key]) && $known[$key] !== $required) {
                    throw ValidationException::withMessages(['erp_product_tax_profile_id' => 'O perfil tributário não é compatível com o contexto informado.']);
                }
            }
        }
        $intents = $current?->tax_field_intents ?? [];
        foreach (ErpProductTaxFields::names() as $field) {
            $hasValue = array_key_exists($field, $data);
            $hasMode = array_key_exists($field, $submitted);
            if (! $hasValue && ! $hasMode) {
                continue;
            }
            $mode = $submitted[$field] ?? ($data[$field] === null ? 'unset' : 'value');
            $effectiveValue = $hasValue ? $data[$field] : $current?->getAttribute($field);
            if (($mode === 'value' && $effectiveValue === null)
                || ($hasValue && $mode === 'inherit')
                || ($hasValue && $mode === 'unset' && $data[$field] !== null)) {
                throw ValidationException::withMessages(['tax_field_intents.'.$field => 'A intenção e o valor tributário informados são incompatíveis.']);
            }
            $intents[$field] = $mode;
            if ($mode !== 'value') {
                $data[$field] = null;
            }
        }
        if ($intents !== [] || array_key_exists('tax_field_intents', $data)) {
            $data['tax_field_intents'] = $intents;
        }

        return $data;
    }
}
