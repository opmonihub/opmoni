<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpInvoiceScheme;
use App\Support\AccessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpCompanyInitializer
{
    public function __construct(
        private ErpCompanySetupPreset $preset,
        private ErpCompanyOperatingSettings $settings,
    ) {}

    /** Internal provisioning only; existing-company adoption requires the separate reviewed reconciliation flow. */
    public function initialize(ErpCompany $company): ErpCompanySetupState
    {
        return DB::transaction(function () use ($company): ErpCompanySetupState {
            AccessTransaction::boundLockWaits();
            $company = ErpCompany::query()->lockForUpdate()->findOrFail($company->id);
            $state = ErpCompanySetupState::query()->forCompany($company->id)->lockForUpdate()->first();
            if ($state !== null) {
                return $state;
            }

            $manifest = $this->preset->manifest();
            $unit = $this->unit($company, $manifest['records']['unit']);
            $customer = $this->customer($company, $manifest['records']['customer']);
            $scheme = $this->saleScheme($company, $manifest['records']['sale_scheme']);
            $layout = $this->saleLayout($company, $manifest['records']['sale_layout']);
            $stored = is_array($company->operating_settings) ? $company->operating_settings : [];
            $attributes = $this->settings->extractPersistable($this->preset->missingOperatingSettings($stored));
            $this->settings->mergeOperating($company, $attributes['operating_settings']);
            $company->save();

            return ErpCompanySetupState::query()->create([
                'erp_company_id' => $company->id,
                'preset_version' => $manifest['version'],
                'revision' => 1,
                'currency' => $manifest['currency'],
                'default_unit_id' => $unit->id,
                'default_customer_id' => $customer->id,
                'default_invoice_scheme_id' => $scheme->id,
                'default_invoice_layout_id' => $layout->id,
                'sale_numbering_adopted' => true,
                'preset_owned_keys' => [
                    'unit' => $manifest['records']['unit']['key'],
                    'customer' => $manifest['records']['customer']['key'],
                    'sale_scheme' => $manifest['records']['sale_scheme']['key'],
                    'sale_layout' => $manifest['records']['sale_layout']['key'],
                ],
            ]);
        });
    }

    /** @param array{key: string, attributes: array<string, mixed>} $definition */
    private function unit(ErpCompany $company, array $definition): ErpUnit
    {
        $unit = ErpUnit::query()->forCompany($company->id)->where('native_key', $definition['key'])->lockForUpdate()->first();
        if ($unit !== null) {
            if ($unit->short_name !== 'UN' || $unit->actual_name !== 'Unidade' || $unit->allow_decimal
                || $unit->base_unit_id !== null || $unit->conversion_factor !== null || $unit->status !== 'active') {
                throw ValidationException::withMessages(['initialization.unit' => 'A unidade inicial foi alterada. Revise a configuração antes de continuar.']);
            }

            return $unit;
        }

        if (ErpUnit::query()->forCompany($company->id)->where('short_name', $definition['attributes']['short_name'])->exists()) {
            throw ValidationException::withMessages(['initialization.unit' => 'Já existe uma unidade UN. Revise seu uso antes de aplicar a configuração inicial.']);
        }

        $unit = new ErpUnit(['erp_company_id' => $company->id, ...$definition['attributes']]);
        $unit->forceFill(['native_key' => $definition['key']])->save();

        return $unit;
    }

    /** @param array{key: string, attributes: array<string, mixed>} $definition */
    private function customer(ErpCompany $company, array $definition): ErpContact
    {
        $customer = ErpContact::query()->forCompany($company->id)->where('native_key', $definition['key'])->lockForUpdate()->first();
        if ($customer !== null) {
            if (! $customer->is_customer || $customer->status !== 'active') {
                throw ValidationException::withMessages(['initialization.customer' => 'O cliente inicial foi alterado. Revise a configuração antes de continuar.']);
            }

            return $customer;
        }

        $customer = new ErpContact(['erp_company_id' => $company->id, ...$definition['attributes']]);
        $customer->forceFill(['native_key' => $definition['key']])->save();

        return $customer;
    }

    /** @param array{key: string, attributes: array<string, mixed>} $definition */
    private function saleScheme(ErpCompany $company, array $definition): ErpInvoiceScheme
    {
        $scheme = ErpInvoiceScheme::query()->where('erp_company_id', $company->id)->where('native_key', $definition['key'])->lockForUpdate()->first();
        if ($scheme !== null) {
            if ($scheme->status !== 'active') {
                throw ValidationException::withMessages(['initialization.sale_scheme' => 'A numeração inicial está arquivada. Revise a configuração antes de continuar.']);
            }

            return $scheme;
        }
        $scheme = new ErpInvoiceScheme(['erp_company_id' => $company->id, ...$definition['attributes']]);
        $scheme->forceFill(['native_key' => $definition['key']])->save();

        return $scheme;
    }

    /** @param array{key: string, attributes: array<string, mixed>} $definition */
    private function saleLayout(ErpCompany $company, array $definition): ErpInvoiceLayout
    {
        $layout = ErpInvoiceLayout::query()->where('erp_company_id', $company->id)->where('native_key', $definition['key'])->lockForUpdate()->first();
        if ($layout !== null) {
            if ($layout->status !== 'active') {
                throw ValidationException::withMessages(['initialization.sale_layout' => 'O modelo comercial inicial está arquivado. Revise a configuração antes de continuar.']);
            }

            return $layout;
        }
        $layout = new ErpInvoiceLayout(['erp_company_id' => $company->id, ...$definition['attributes']]);
        $layout->forceFill(['native_key' => $definition['key']])->save();

        return $layout;
    }
}
