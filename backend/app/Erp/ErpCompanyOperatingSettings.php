<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;

/**
 * Maps legacy Business settings fields (reference BusinessController@postBusinessSettings).
 */
final class ErpCompanyOperatingSettings
{
    /** @var list<string> */
    public const SCALAR_KEYS = [
        'start_date', 'currency_id', 'tax_label_1', 'tax_number_1', 'tax_label_2', 'tax_number_2',
        'default_profit_percent', 'default_sales_tax', 'default_sales_discount', 'sell_price_tax', 'sku_prefix',
        'time_zone', 'fy_start_month', 'accounting_method', 'transaction_edit_days', 'sales_cmsn_agnt',
        'item_addition_method', 'currency_symbol_placement', 'on_product_expiry', 'stop_selling_before',
        'default_unit', 'expiry_type', 'date_format', 'time_format', 'theme_color', 'rp_name', 'amount_for_unit_rp',
        'min_order_total_for_rp', 'max_rp_per_order', 'redeem_amount_per_unit_rp', 'min_order_total_for_redeem',
        'min_redeem_point', 'max_redeem_point', 'rp_expiry_period', 'rp_expiry_type', 'cidade_id', 'city', 'rua',
        'numero', 'bairro', 'cep', 'telefone', 'ultimo_numero_nfe', 'ultimo_numero_nfce', 'ultimo_numero_cte',
        'ultimo_numero_mdfe', 'inscricao_municipal', 'numero_serie_nfe', 'numero_serie_nfce', 'ambiente', 'regime',
        'cst_csosn_padrao', 'cst_pis_padrao', 'cst_cofins_padrao', 'cst_ipi_padrao', 'csc', 'csc_id',
        'perc_icms_padrao', 'perc_pis_padrao', 'perc_cofins_padrao', 'perc_ipi_padrao', 'ncm_padrao',
        'cfop_saida_estadual_padrao', 'cfop_saida_inter_estadual_padrao', 'aut_xml', 'casas_decimais_valor',
        'numero_serie_cte', 'numero_serie_mdfe', 'pCredSN', 'natureza_operacao_pdv', 'exclusao_icms_pis_cofins',
        'purchase_in_diff_currency', 'purchase_currency_id', 'p_exchange_rate', 'enable_editing_product_from_purchase',
        'enable_purchase_status', 'enable_lot_number', 'enable_tooltip', 'enable_product_expiry', 'stock_expiry_alert_days',
        'enable_rp', 'enable_inline_tax', 'enable_brand', 'enable_category', 'enable_sub_category', 'enable_price_tax',
        'enable_racks', 'enable_row', 'enable_position', 'enable_sub_units', 'certificado_urn',
    ];

    /** @var list<string> */
    public const JSON_KEYS = [
        'ref_no_prefixes', 'keyboard_shortcuts', 'pos_settings', 'email_settings', 'sms_settings', 'custom_labels',
        'common_settings', 'weighing_scale_setting', 'enabled_modules',
    ];

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return array_merge(
            [
                'time_zone' => 'America/Sao_Paulo',
                'fy_start_month' => 1,
                'accounting_method' => 'fifo',
                'transaction_edit_days' => 30,
                'date_format' => 'd/m/Y',
                'time_format' => 12,
                'currency_symbol_placement' => 'before',
                'casas_decimais_valor' => 2,
                'ambiente' => 2,
                'regime' => 1,
            ],
            [
                'pos_settings' => LegacyBusinessReference::defaultPosSettings(),
                'email_settings' => LegacyBusinessReference::defaultEmailSettings(),
                'sms_settings' => LegacyBusinessReference::defaultSmsSettings(),
                'ref_no_prefixes' => [],
                'keyboard_shortcuts' => [],
                'custom_labels' => [],
                'common_settings' => [],
                'weighing_scale_setting' => [],
                'enabled_modules' => [],
            ],
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(ErpCompany $company): array
    {
        $stored = is_array($company->operating_settings) ? $company->operating_settings : [];
        $merged = array_replace($this->defaults(), $stored);

        return [
            'name' => $company->name,
            'cnpj' => $company->cnpj,
            'razao_social' => $company->razao_social,
            'ie' => $company->ie,
            'status' => $company->status,
            ...$merged,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name?: string, cnpj?: string, razao_social?: ?string, ie?: ?string, operating_settings: array<string, mixed>}
     */
    public function extractPersistable(array $input): array
    {
        $top = [];
        foreach (['name', 'cnpj', 'razao_social', 'ie'] as $key) {
            if (array_key_exists($key, $input)) {
                $top[$key] = $input[$key];
            }
        }

        $settings = [];
        foreach (array_merge(self::SCALAR_KEYS, self::JSON_KEYS) as $key) {
            if (array_key_exists($key, $input)) {
                $settings[$key] = $input[$key];
            }
        }

        if (array_key_exists('shortcuts', $input)) {
            $settings['keyboard_shortcuts'] = $input['shortcuts'];
        }

        return [
            ...$top,
            'operating_settings' => $settings,
        ];
    }

    /** @param  array<string, mixed>  $persistedSettings */
    public function mergeOperating(ErpCompany $company, array $persistedSettings): void
    {
        $current = is_array($company->operating_settings) ? $company->operating_settings : [];
        $company->operating_settings = array_replace($current, $persistedSettings);
    }
}
