<?php

namespace App\Erp;

/**
 * Stateless helpers copied from opmonihub/sistema Business / BusinessUtil (reference e7b77be).
 */
final class LegacyBusinessReference
{
    /** @return array<string, string> */
    public static function dateFormats(): array
    {
        return [
            'd-m-Y' => 'dd-mm-yyyy',
            'm-d-Y' => 'mm-dd-yyyy',
            'd/m/Y' => 'dd/mm/yyyy',
            'm/d/Y' => 'mm/dd/yyyy',
        ];
    }

    /** @return array<string, string> */
    public static function accountingMethods(): array
    {
        return [
            'fifo' => 'FIFO',
            'lifo' => 'LIFO',
        ];
    }

    /** @return array<int, string> */
    public static function fiscalYearMonths(): array
    {
        return [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ];
    }

    /** @return array<string, int> */
    public static function defaultPosSettings(): array
    {
        return [
            'disable_pay_checkout' => 0,
            'disable_draft' => 0,
            'disable_express_checkout' => 0,
            'hide_product_suggestion' => 0,
            'hide_recent_trans' => 0,
            'disable_discount' => 0,
            'disable_order_tax' => 0,
            'is_pos_subtotal_editable' => 0,
        ];
    }

    /** @return array<string, string> */
    public static function defaultEmailSettings(): array
    {
        return [
            'mail_host' => '',
            'mail_port' => '',
            'mail_username' => '',
            'mail_password' => '',
            'mail_encryption' => '',
            'mail_from_address' => '',
            'mail_from_name' => '',
        ];
    }

    /** @return array<string, string> */
    public static function defaultSmsSettings(): array
    {
        return [
            'url' => '',
            'send_to_param_name' => 'to',
            'msg_param_name' => 'text',
            'request_method' => 'post',
            'param_1' => '',
            'param_val_1' => '',
            'param_2' => '',
            'param_val_2' => '',
            'param_3' => '',
            'param_val_3' => '',
            'param_4' => '',
            'param_val_4' => '',
            'param_5' => '',
            'param_val_5' => '',
        ];
    }

    public static function ufToIbgeCode(string $uf): ?string
    {
        $map = [
            'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
            'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27',
            'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35', 'PR' => '41',
            'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
        ];

        return $map[strtoupper($uf)] ?? null;
    }
}
