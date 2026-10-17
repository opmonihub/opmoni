<?php

namespace App\Support;

class SupportAccessLogMetadata
{
    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (self::isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? self::sanitize($value)
                : $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalized = strtolower($key);

        if (in_array($normalized, [
            'password',
            'token',
            'secret',
            'session_id',
            'csrf',
            'cookie',
            'authorization',
            'consumer_secret',
            'consumer_key',
            'session',
            'container',
        ], true)) {
            return true;
        }

        return str_contains($normalized, 'password')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'session');
    }
}
