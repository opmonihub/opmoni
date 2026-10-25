<?php

namespace App\Erp\Commercial;

use Illuminate\Validation\ValidationException;

final class OfficeErpMoney
{
    private const SCALE = 2;

    public static function canonical(mixed $value, string $field = 'amount'): string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Informe um valor monetário não negativo com até duas casas decimais.']);
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function add(string $left, string $right): string
    {
        return self::arithmetic(bcadd(self::signed($left), self::signed($right), self::SCALE));
    }

    public static function subtract(string $left, string $right): string
    {
        return self::arithmetic(bcsub(self::signed($left), self::signed($right), self::SCALE));
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp(self::signed($left), self::signed($right), self::SCALE);
    }

    private static function signed(string $value): string
    {
        if (! preg_match('/^-?\d{1,18}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor monetário válido com até duas casas decimais.']);
        }

        return bcadd($value, '0', self::SCALE);
    }

    private static function arithmetic(string $result): string
    {
        if (! preg_match('/^-?(?:0|[1-9]\d{0,17})\.\d{2}$/D', $result)) {
            throw ValidationException::withMessages(['amount' => 'O resultado ultrapassa o limite monetário suportado.']);
        }

        return $result === '-0.00' ? '0.00' : $result;
    }
}
