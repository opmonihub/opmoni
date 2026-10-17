<?php

namespace App\Erp\Finance;

/**
 * Fixed-scale decimal helpers ported from sistema {@see Util::num_uf} semantics.
 */
final class ErpDecimal
{
    public const SCALE = 4;

    public static function parse(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_int($value) || is_float($value)) {
            return self::normalize((string) $value);
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
            return self::zero();
        }

        return bcadd($value, '0', self::SCALE);
    }
}
