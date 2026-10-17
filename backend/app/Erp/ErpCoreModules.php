<?php

namespace App\Erp;

final class ErpCoreModules
{
    /** @var list<string> */
    public const BASIC = [
        'purchases',
        'add_sale',
        'pos_sale',
        'stock_transfers',
        'stock_adjustment',
        'expenses',
        'cte',
        'mdfe',
    ];

    public static function isKnown(string $moduleKey): bool
    {
        return in_array($moduleKey, self::BASIC, true);
    }

    /** @return list<string> */
    public static function validateKeys(array $keys): array
    {
        $invalid = array_values(array_filter($keys, fn (mixed $key): bool => ! is_string($key) || ! self::isKnown($key)));

        return $invalid;
    }
}
