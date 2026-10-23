<?php

namespace App\Erp;

use Closure;
use Illuminate\Validation\ValidationException;

final class ErpCatalogDecimal
{
    /**
     * Validate a decimal string against a database column's integer and fraction widths.
     *
     * @return list<Closure(string, mixed, Closure(string): void): void>
     */
    public static function widthRule(int $integerDigits, int $fractionDigits, bool $signed = false): array
    {
        return [function (string $attribute, mixed $value, Closure $fail) use ($integerDigits, $fractionDigits, $signed): void {
            if (! is_string($value)) {
                $fail('Informe um valor decimal como texto.');

                return;
            }

            $sign = $signed ? '-?' : '';
            $fraction = $fractionDigits > 0 ? '(?:\.\d{1,'.$fractionDigits.'})?' : '';
            if (! preg_match('/^'.$sign.'\d+'.$fraction.'$/D', $value)) {
                $fail('Informe um decimal válido com até '.$fractionDigits.' casas decimais.');

                return;
            }

            $whole = ltrim(explode('.', ltrim($value, '-'), 2)[0], '0');
            if (strlen($whole) > $integerDigits) {
                $fail('O valor excede o limite permitido.');
            }
        }];
    }

    public static function assertPositive(string $attribute, mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw ValidationException::withMessages([$attribute => 'Informe um valor decimal positivo.']);
        }

        $digits = str_replace('.', '', $value);
        if (preg_match('/[1-9]/', $digits) !== 1) {
            throw ValidationException::withMessages([$attribute => 'Informe um valor decimal positivo.']);
        }
    }
}
