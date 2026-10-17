<?php

namespace App\Erp\LegacyPort;

use App\Models\Erp\ErpCompany;
use Carbon\Carbon;

/**
 * Stateless helpers ported from opmonihub/sistema Util (+ ProductUtil invoice math).
 *
 * @source opmonihub/sistema@e7b77be app/Utils/Util.php
 * @source opmonihub/sistema@e7b77be app/Utils/ProductUtil.php
 * @source opmonihub/sistema@e7b77be app/Utils/CashRegisterUtil.php (num_uf via Util)
 */
class LegacyErpUtil
{
    /**
     * @source Util::num_uf
     */
    public function num_uf(mixed $input_number, mixed $currency_details = null): float
    {
        $thousand_separator = '';
        $decimal_separator = ',';

        $num = $input_number;

        if (str_contains((string) $num, '.') && ! str_contains((string) $num, ',')) {
            return (float) $num;
        }
        $num = str_replace('.', '', (string) $input_number);
        $num = str_replace(',', '.', $num);

        return (float) $num;
    }

    /**
     * @source Util::convert_num
     */
    public function convert_num(mixed $number): float
    {
        $number = str_replace('.', '', (string) $number);
        $number = str_replace(',', ';', $number);

        return (float) $number;
    }

    /**
     * @source Util::calc_percentage
     */
    public function calc_percentage(float|int $number, float|int $percent, float|int $addition = 0): float
    {
        return $addition + ($number * ($percent / 100));
    }

    /**
     * @source Util::calc_percentage_base
     */
    public function calc_percentage_base(float|int $number, float|int $percent): float
    {
        return ($number * 100) / (100 + $percent);
    }

    /**
     * @source Util::get_percent
     */
    public function get_percent(float|int $base, float|int $number): float
    {
        if ($base == 0) {
            return 0;
        }

        $diff = $number - $base;

        return ($diff / $base) * 100;
    }

    /**
     * @source Util::generateReferenceNumber
     *
     * @param  array<string, mixed>|null  $businessConfig  Legacy `business` session slice or operating_settings
     */
    public function generateReferenceNumber(
        string $type,
        int $ref_count,
        ?ErpCompany $company = null,
        ?array $businessConfig = null,
        ?string $default_prefix = null
    ): string {
        $prefix = '';

        $prefixes = null;
        if ($businessConfig !== null && ! empty($businessConfig['ref_no_prefixes'][$type])) {
            $prefix = (string) $businessConfig['ref_no_prefixes'][$type];
        } elseif ($company !== null) {
            $settings = $company->operating_settings ?? [];
            $prefixes = $settings['ref_no_prefixes'] ?? [];
            if (! empty($prefixes[$type])) {
                $prefix = (string) $prefixes[$type];
            }
        }

        if (! empty($default_prefix)) {
            $prefix = $default_prefix;
        }

        $ref_digits = str_pad((string) $ref_count, 4, '0', STR_PAD_LEFT);

        if (! in_array($type, ['contacts', 'business_location', 'username'], true)) {
            $ref_year = Carbon::now()->year;
            $ref_number = $prefix.$ref_year.'/'.$ref_digits;
        } else {
            $ref_number = $prefix.$ref_digits;
        }

        return $ref_number;
    }

    /**
     * @source ProductUtil::calculateInvoiceTotal (tax/discount math only; tax rate passed explicitly)
     *
     * @param  array<int, array<string, mixed>>  $products
     * @param  array{discount_type: string, discount_amount: mixed}|null  $discount
     * @return array{total_before_tax: float, tax: float, discount: float, final_total: float, tax_id?: int}|false
     */
    public function calculateInvoiceTotal(array $products, ?int $tax_id, ?array $discount = null, ?float $tax_rate_percent = null): array|false
    {
        if (empty($products)) {
            return false;
        }

        $output = ['total_before_tax' => 0, 'tax' => 0, 'discount' => 0, 'final_total' => 0];

        foreach ($products as $product) {
            $output['total_before_tax'] += $this->convert_num($product['unit_price_inc_tax']) * $this->num_uf($product['quantity']);

            if (! empty($product['modifier_price'])) {
                foreach ($product['modifier_price'] as $modifier_price) {
                    $output['total_before_tax'] += $this->num_uf($modifier_price);
                }
            }
        }

        if (is_array($discount)) {
            if ($discount['discount_type'] == 'fixed') {
                $output['discount'] = $this->num_uf($discount['discount_amount']);
            } else {
                $output['discount'] = ($this->num_uf($discount['discount_amount']) / 100) * $output['total_before_tax'];
            }
        }

        $output['tax'] = 0;
        if (! empty($tax_id) && $tax_rate_percent !== null) {
            $output['tax_id'] = $tax_id;
            $output['tax'] = ($tax_rate_percent / 100) * ($output['total_before_tax'] - $output['discount']);
        }

        $output['final_total'] = $output['total_before_tax'] + $output['tax'] - $output['discount'];

        return $output;
    }

    /**
     * @source TransactionUtil::__makeLinesForComboProduct (percent change in price)
     */
    public function comboPriceChangePercent(float $combo_total_price, float $parent_line_total): float
    {
        return $this->get_percent($combo_total_price, $parent_line_total);
    }

    /**
     * @source TransactionUtil::__makeLinesForComboProduct
     */
    public function comboChildUnitPrice(float $unit_price_inc_tax, float $change_percent): float
    {
        return $this->calc_percentage($unit_price_inc_tax, $change_percent, $unit_price_inc_tax);
    }
}
