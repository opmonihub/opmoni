<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\ErpProductTaxProfileRevision;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpProductTaxResolver
{
    private const COMPANY_FIELDS = ['pis_cst' => 'cst_pis_padrao', 'cofins_cst' => 'cst_cofins_padrao', 'ipi_cst' => 'cst_ipi_padrao', 'icms_rate' => 'perc_icms_padrao', 'pis_rate' => 'perc_pis_padrao', 'cofins_rate' => 'perc_cofins_padrao', 'ipi_rate' => 'perc_ipi_padrao', 'simple_national_credit_rate' => 'pCredSN'];

    /**
     * Caller supplies an authorized company and a persisted product or an unsaved reviewed candidate.
     * All participating writers acquire the company before default, sorted profiles and product rows.
     *
     * @param  array<string, mixed>  $operationContext
     * @return array<string, mixed>
     */
    public function resolve(CurrentCompany $context, ErpProduct $product, array $operationContext = []): array
    {
        return DB::transaction(function () use ($context, $product, $operationContext): array {
            $company = ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);
            if ((int) $product->erp_company_id !== (int) $company->id) {
                throw ValidationException::withMessages(['product_id' => 'Produto inválido para esta empresa.']);
            }
            $default = ErpCompanyProductTaxDefault::query()->where('erp_company_id', $company->id)->lockForUpdate()->first();
            $ids = array_values(array_unique(array_filter([$product->erp_product_tax_profile_id, $default?->erp_product_tax_profile_id], fn ($id): bool => $id !== null)));
            $profiles = ErpProductTaxProfile::query()->where('erp_company_id', $company->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($product->exists) {
                ErpProduct::query()->where('erp_company_id', $company->id)->whereKey($product->id)->lockForUpdate()->firstOrFail();
            }
            $settings = $company->operating_settings ?? [];
            $actual = [
                'tax_regime' => $operationContext['tax_regime'] ?? (isset($settings['regime']) && in_array((string) $settings['regime'], ['1', '2', '3', '4'], true) ? (string) $settings['regime'] : null),
                'kind' => $product->kind ?? 'goods',
                'movement' => $operationContext['movement'] ?? null,
            ];
            $selected = $product->erp_product_tax_profile_id === null ? null : $this->revision($profiles->get($product->erp_product_tax_profile_id), $actual, 'erp_product_tax_profile_id');
            $defaultRevision = null;
            $values = $sources = $unresolved = [];
            foreach (ErpProductTaxFields::names() as $field) {
                $raw = $product->getAttribute($field);
                $mode = ($product->tax_field_intents ?? [])[$field] ?? ($raw === null ? 'unset' : 'value');
                if ($mode === 'value') {
                    $values[$field] = $raw;
                    $sources[$field] = ['source' => 'product', 'intent' => 'value'];
                } elseif ($mode === 'unset') {
                    $values[$field] = null;
                    $sources[$field] = ['source' => 'unset', 'intent' => 'unset'];
                } else {
                    if ($selected !== null && array_key_exists($field, $selected->fields)) {
                        $values[$field] = $selected->fields[$field];
                        $sources[$field] = $this->source('profile', $selected);
                    } else {
                        if ($default?->erp_product_tax_profile_id !== null && $defaultRevision === null) {
                            $defaultRevision = $this->revision($profiles->get($default->erp_product_tax_profile_id), $actual, 'default_product_tax_profile_id');
                        }
                        if ($defaultRevision !== null && array_key_exists($field, $defaultRevision->fields)) {
                            $values[$field] = $defaultRevision->fields[$field];
                            $sources[$field] = $this->source('company_profile', $defaultRevision);
                        } else {
                            $fallback = $this->companyField($field, $settings, $actual);
                            $values[$field] = $fallback['value'];
                            $sources[$field] = $fallback['setting'] === null
                                ? ['source' => 'unresolved', 'intent' => 'inherit']
                                : ['source' => 'company_setting', 'intent' => 'inherit', 'setting' => $fallback['setting']];
                        }
                    }
                }
                if ($values[$field] === null) {
                    $unresolved[] = $field;
                }
            }
            $relevantSettings = array_intersect_key($settings, array_flip(['regime', 'cst_csosn_padrao', ...array_values(self::COMPANY_FIELDS)]));
            ksort($relevantSettings);
            $result = [
                'version' => 'product-tax-v1', 'product_id' => $product->id,
                'values' => $values, 'sources' => $sources, 'unresolved_fields' => $unresolved,
                'profile' => $selected === null ? null : $this->identity($selected),
                'company_default' => ['revision' => $default?->revision ?? 0, 'profile_id' => $default?->erp_product_tax_profile_id, 'profile_revision' => $defaultRevision?->revision, 'profile_revision_id' => $defaultRevision?->id],
                'context' => $actual,
                'applicability' => ['profile' => $selected?->applicability, 'company_profile' => $defaultRevision?->applicability],
                'company_settings_fingerprint' => hash('sha256', json_encode($relevantSettings, JSON_THROW_ON_ERROR)),
                'errors' => [],
            ];
            $result['fingerprint'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

            return $result;
        });
    }

    private function revision(?ErpProductTaxProfile $profile, array $actual, string $field): ErpProductTaxProfileRevision
    {
        if ($profile === null || $profile->status !== 'active' || $profile->current_revision === null) {
            throw ValidationException::withMessages([$field => 'Perfil tributário indisponível. Revise a seleção sem substituir seus valores.']);
        }
        $revision = $profile->revisions()->where('erp_company_id', $profile->erp_company_id)->where('revision', $profile->current_revision)->first();
        if ($revision === null || $revision->status !== 'active') {
            throw ValidationException::withMessages([$field => 'A revisão do perfil tributário está indisponível.']);
        }
        foreach ($revision->applicability as $key => $required) {
            if (($actual[$key] ?? null) === null) {
                throw ValidationException::withMessages([$field => 'Informe o contexto necessário para avaliar o perfil tributário.', 'context.'.$key => 'Contexto tributário necessário para este perfil.']);
            }
            if ($actual[$key] !== $required) {
                throw ValidationException::withMessages([$field => 'O perfil tributário não é compatível com o contexto informado.']);
            }
        }

        return $revision;
    }

    private function identity(ErpProductTaxProfileRevision $revision): array
    {
        return ['profile_id' => $revision->erp_product_tax_profile_id, 'revision' => $revision->revision, 'revision_id' => $revision->id];
    }

    private function source(string $source, ErpProductTaxProfileRevision $revision): array
    {
        return ['source' => $source, 'intent' => 'inherit', ...$this->identity($revision)];
    }

    private function companyField(string $field, array $settings, array $actual): array
    {
        $key = self::COMPANY_FIELDS[$field] ?? null;
        if (($field === 'icms_cst' && $actual['tax_regime'] === '3') || ($field === 'icms_csosn' && $actual['tax_regime'] === '1')) {
            $key = 'cst_csosn_padrao';
        }
        if ($actual['tax_regime'] === null || $key === null || ! array_key_exists($key, $settings) || ! is_string($settings[$key])) {
            return ['value' => null, 'setting' => null];
        }
        $value = $settings[$key];
        if (Validator::make([$field => $value], ErpProductTaxFields::rules())->fails()) {
            return ['value' => null, 'setting' => null];
        }

        return ['value' => $value, 'setting' => $key];
    }
}
