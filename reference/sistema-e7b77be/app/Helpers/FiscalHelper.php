<?php

namespace App\Helpers;

class FiscalHelper
{
    public static function onlyDigits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v) ?? '';
    }

    public static function cep(?string $v): string
    {
        return self::onlyDigits($v);
    }

    public static function truncate(?string $v, int $max): string
    {
        return mb_substr(trim((string) $v), 0, $max, 'UTF-8');
    }
}
