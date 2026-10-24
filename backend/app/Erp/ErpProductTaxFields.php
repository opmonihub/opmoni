<?php

namespace App\Erp;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpProductTaxFields
{
    public const CODE_WIDTHS = ['icms_cst' => 2, 'icms_csosn' => 3, 'pis_cst' => 2, 'cofins_cst' => 2, 'ipi_cst' => 2, 'ibs_cbs_cst' => 3, 'ibs_cbs_classification_code' => 6];

    public const RATES = ['icms_rate', 'pis_rate', 'cofins_rate', 'ipi_rate', 'simple_national_credit_rate'];

    /** @return list<string> */
    public static function names(): array
    {
        return [...array_keys(self::CODE_WIDTHS), ...self::RATES];
    }

    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = ''): array
    {
        $rules = [];
        foreach (self::CODE_WIDTHS as $field => $width) {
            $rules[$prefix.$field] = ['sometimes', 'nullable', 'string', 'regex:/\A\d{'.$width.'}\z/'];
        }
        foreach (self::RATES as $field) {
            $rules[$prefix.$field] = ['sometimes', 'nullable', ...ErpCatalogDecimal::widthRule(3, 4)];
        }

        return $rules;
    }

    /** @param array<string, mixed> $fields */
    public static function validate(array $fields): array
    {
        foreach (array_diff(array_keys($fields), self::names()) as $field) {
            throw ValidationException::withMessages(['fields.'.$field => 'Campo não permitido no perfil tributário.']);
        }

        return Validator::make(['fields' => $fields], self::rules('fields.'))->validate()['fields'] ?? [];
    }
}
