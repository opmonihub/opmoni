<?php

namespace App\Erp\Finance;

use Illuminate\Validation\ValidationException;

/**
 * Fixed-scale decimal helpers ported from sistema {@see Util::num_uf} semantics.
 */
final class ErpDecimal
{
    public const SCALE = 4;

    public static function parse(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_int($value) || is_float($value)) {
            return self::normalize((string) $value);
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['amount' => 'Valor decimal inválido']);
        }
        $input = trim($value);
        if (str_contains($input, '.') && ! str_contains($input, ',')) {
            return self::normalize($input);
        }

        $normalized = str_replace('.', '', $input);
        $normalized = str_replace(',', '.', $normalized);

        return self::normalize($normalized);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd(self::parse($left), self::parse($right), self::SCALE);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub(self::parse($left), self::parse($right), self::SCALE);
    }

    public static function mul(string $left, string $right): string
    {
        return bcmul(self::parse($left), self::parse($right), self::SCALE);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp(self::parse($left), self::parse($right), self::SCALE);
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, self::zero()) === 1;
    }

    public static function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    private static function normalize(string $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(['amount' => 'Valor decimal inválido']);
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if (! preg_match('/^-?\d{1,18}(?:\.\d{1,4})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Valor decimal inválido ou precisão não suportada']);
        }

        return bcadd($value, '0', self::SCALE);
    }
}
