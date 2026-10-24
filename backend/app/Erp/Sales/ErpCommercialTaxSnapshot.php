<?php

namespace App\Erp\Sales;

use App\Erp\ErpCfopResolver;
use App\Erp\ErpProductTaxFields;
use App\Erp\ErpProductTaxResolver;
use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\ErpProductVariant;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpCommercialTaxSnapshot
{
    /** Compare reviewed identities and facts under the existing Company-first configuration locks; never resolve new values. */
    public function currentPreconditionsMatch(CurrentCompany $context, Model $document): bool
    {
        $document->loadMissing('lines');
        $this->lock($context, $document->lines->pluck('erp_product_variant_id')->filter()->all(), array_filter([$document->erp_operation_nature_id]));
        $company = $context->company->fresh();
        $settings = app(ErpProductTaxResolver::class)->configurationFingerprint($company);
        $default = ErpCompanyProductTaxDefault::query()->where('erp_company_id', $company->id)->first();
        foreach ($document->lines as $line) {
            $facts = $line->product_snapshot ?? [];
            if ($line->erp_product_variant_id === null) {
                continue;
            }
            $product = ErpProductVariant::query()->whereKey($line->erp_product_variant_id)->whereHas('product', fn ($query) => $query->where('erp_company_id', $company->id))->first()?->product;
            $tax = $facts['product_tax_resolution'] ?? [];
            $configuration = $facts['product_tax_configuration'] ?? [];
            if ($product === null || $product->status !== 'active' || ($facts['version'] ?? null) !== ErpCommercialSnapshotVersions::RESOLVED
                || ($tax['company_settings_fingerprint'] ?? null) !== $settings
                || $configuration !== ['profile_id' => $product->erp_product_tax_profile_id, 'intents' => $product->tax_field_intents, 'values' => $product->only(ErpProductTaxFields::names())]) {
                return false;
            }
            foreach (['ncm', 'origin_code', 'kind'] as $field) {
                if (($facts['product'][$field] ?? null) !== $product->$field) {
                    return false;
                }
            }
            foreach (['profile', 'company_profile'] as $source) {
                foreach ($tax['sources'] ?? [] as $used) {
                    if (($used['source'] ?? null) !== $source) {
                        continue;
                    }
                    $profile = ErpProductTaxProfile::query()->where('erp_company_id', $company->id)->whereKey($used['profile_id'])->first();
                    if ($profile === null || $profile->status !== 'active' || $profile->current_revision !== $used['revision']) {
                        return false;
                    }
                }
            }
            $sources = array_column($tax['sources'] ?? [], 'source');
            if (array_intersect($sources, ['company_profile', 'company_setting', 'unresolved']) !== []
                && (($tax['company_default']['revision'] ?? 0) !== ($default?->revision ?? 0)
                    || ($tax['company_default']['profile_id'] ?? null) !== $default?->erp_product_tax_profile_id)) {
                return false;
            }
            $cfop = $facts['cfop_resolution'] ?? [];
            if (($cfop['nature_id'] ?? null) !== null) {
                $nature = ErpOperationNature::query()->where('erp_company_id', $company->id)->whereKey($cfop['nature_id'])->first();
                if ($nature === null || $nature->status !== 'active' || $nature->configuration_revision !== ($cfop['configuration_revision'] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function context(array $input, int $establishment): array
    {
        $data = Validator::make($input, [
            'tax_context' => ['sometimes', 'array:origin_uf,destination_uf,model,purpose,final_consumer,presence'],
        ])->validate();

        try {
            return app(ErpCfopResolver::class)->operationContext([
                ...($data['tax_context'] ?? []), 'erp_establishment_id' => $establishment, 'movement' => 'out',
            ]);
        } catch (ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) {
                $errors[str_replace('context.', 'tax_context.', $field)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    /** The caller already holds the Company lock; obtain the entire local configuration set first. */
    public function lock(CurrentCompany $context, array $variantIds, array $natureIds): void
    {
        $company = $context->company->id;
        ErpCompanySetupState::query()->where('erp_company_id', $company)->lockForUpdate()->first();
        $default = ErpCompanyProductTaxDefault::query()->where('erp_company_id', $company)->lockForUpdate()->first();
        $productIds = ErpProductVariant::query()->whereIn('id', $variantIds)->whereHas('product', fn ($query) => $query->where('erp_company_id', $company))->pluck('erp_product_id')->unique()->all();
        $profileIds = ErpProduct::query()->where('erp_company_id', $company)->whereIn('id', $productIds)->pluck('erp_product_tax_profile_id')->filter()->all();
        if ($default?->erp_product_tax_profile_id !== null) {
            $profileIds[] = $default->erp_product_tax_profile_id;
        }
        ErpProductTaxProfile::query()->where('erp_company_id', $company)->whereIn('id', $profileIds)->orderBy('id')->lockForUpdate()->get();
        $natures = ErpOperationNature::query()->where('erp_company_id', $company)->whereIn('id', $natureIds)->orderBy('id')->lockForUpdate()->get();
        foreach ($natures as $nature) {
            $nature->cfopChoices()->where('erp_company_id', $company)->orderBy('id')->lockForUpdate()->get();
            $nature->productExceptions()->where('erp_company_id', $company)->whereIn('erp_product_id', $productIds)->orderBy('id')->lockForUpdate()->get();
        }
        ErpProduct::query()->where('erp_company_id', $company)->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
    }

    public function explicit(array $line, ?array $previous, string $path = 'line'): ?string
    {
        $old = $previous['cfop_resolution']['explicit_choice'] ?? null;

        try {
            return app(ErpCfopResolver::class)->explicitChoice($line, ['cfop' => $old]);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages([$path.'.cfop' => $error->errors()['line.cfop']]);
        }
    }

    public function retain(?array $previous, array $line, ?ErpProduct $product, ?ErpOperationNature $nature, array $actual, bool $sameContext): bool
    {
        return $previous !== null && $sameContext && ! array_key_exists('cfop', $line)
            && (int) ($previous['variant']['id'] ?? 0) === (int) ($line['erp_product_variant_id'] ?? 0)
            && (int) ($previous['product']['id'] ?? 0) === (int) ($product?->id ?? 0)
            && (int) ($previous['operation_nature']['id'] ?? 0) === (int) ($nature?->id ?? 0)
            && (($previous['cfop_resolution']['context'] ?? $actual) === $actual);
    }

    /** Update classification only; pricing, stock identity, amounts and standalone taxes remain frozen. */
    public function project(CurrentCompany $context, ?ErpProduct $product, ?ErpOperationNature $nature, array $snapshot, array $actual, ?string $explicit, string $path): array
    {
        if ($product === null) {
            if ($explicit !== null) {
                throw ValidationException::withMessages([$path.'.cfop' => 'Selecione um produto para revisar o CFOP.']);
            }

            return $snapshot;
        }
        try {
            $tax = app(ErpProductTaxResolver::class)->resolve($context, $product, ['movement' => $actual['movement']]);
            $cfop = app(ErpCfopResolver::class)->resolve($context, $product, $nature, $actual, $explicit);
        } catch (ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) {
                $key = str_starts_with($field, 'context.') ? 'tax_context.'.substr($field, 8) : $path.'.'.(str_starts_with($field, 'line.') ? substr($field, 5) : $field);
                $errors[$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $snapshot['version'] = ErpCommercialSnapshotVersions::RESOLVED;
        $snapshot['product'] = [...($snapshot['product'] ?? []), ...$tax['values']];
        $snapshot['product_tax_resolution'] = $tax;
        $snapshot['product_tax_configuration'] = ['profile_id' => $product->erp_product_tax_profile_id, 'intents' => $product->tax_field_intents, 'values' => $product->only(ErpProductTaxFields::names())];
        $snapshot['cfop_resolution'] = [...$cfop, 'explicit_choice' => $explicit];
        $snapshot['operation_nature'] = $nature === null ? null : [
            ...$nature->only(['id', 'description', 'movement', 'erp_establishment_id']),
            'cfop' => $cfop['effective_cfop'], 'default_cfop' => $cfop['default_cfop'], 'configuration_revision' => $cfop['configuration_revision'],
        ];

        return $snapshot;
    }

    public function copyClassification(array $snapshot, array $previous): array
    {
        foreach (['version', 'product', 'operation_nature', 'product_tax_resolution', 'product_tax_configuration', 'cfop_resolution'] as $field) {
            if (array_key_exists($field, $previous)) {
                $snapshot[$field] = $previous[$field];
            } else {
                unset($snapshot[$field]);
            }
        }

        return $snapshot;
    }
}
