<?php

namespace App\Support;

use Illuminate\Session\CacheBasedSessionHandler;
use RuntimeException;

class AbsoluteSessionHandler extends CacheBasedSessionHandler
{
    public function write($sessionId, $data): bool
    {
        $attributes = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $remaining = (int) ($attributes['access_deadline'] ?? time() + 1800) - time();
        if ($remaining <= 0) {
            return $this->destroy($sessionId);
        }
        if (! $this->cache->put($sessionId, $data, $remaining)) {
            throw new RuntimeException('Session persistence unavailable');
        }

        return true;
    }
}
