<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

final class AccessRecoveryBudget
{
    public function sensitive(Request $request, int $actorId, ?string $normalizedIdentity = null): void
    {
        $ip = (string) $request->attributes->get('access.client_ip', $request->ip());
        $this->consume('sensitive:ip:'.$ip, 10);
        if ($actorId > 0) {
            $this->consume('sensitive:actor:'.$actorId, 10);
        }

        if ($normalizedIdentity !== null) {
            $this->recipient($normalizedIdentity);
        }
    }

    public function recipient(string $normalizedIdentity): void
    {
        $identity = mb_strtolower(trim($normalizedIdentity));
        abort_if($identity === '', 422, 'invalid_recovery_identity');
        $this->consume('recovery:identity:'.hash('sha256', $identity), 1);
    }

    private function consume(string $identity, int $limit): void
    {
        $key = 'access-limit:'.hash('sha256', $identity);
        if (config('cache.default') !== 'redis' && config('app.env') === 'production') {
            $response = response()->json(['code' => 'rate_limit_unavailable', 'message' => 'Serviço temporariamente indisponível'], 503);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            abort($response);
        }

        if (config('cache.default') === 'redis') {
            $connection = Redis::connection(config('cache.stores.redis.connection', 'cache'));
            $key = config('cache.prefix').$key;
            $result = $limit === 1
                ? $connection->eval("local ok=redis.call('SET',KEYS[1],'1','EX',60,'NX'); if ok then return {1,60} end; return {0,redis.call('TTL',KEYS[1])}", 1, $key)
                : $connection->eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],60) end; return {n,redis.call('TTL',KEYS[1])}", 1, $key);
            $redisCount = (int) $result[0];
            $count = $limit === 1 && $redisCount === 0 ? 2 : $redisCount;
            $ttl = max(1, (int) $result[1]);
        } elseif ($limit === 1) {
            $allowed = Cache::add($key, 1, 60);
            $count = $allowed ? 1 : 2;
            $ttl = 60;
        } else {
            $count = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $count, 60);
            $ttl = 60;
        }

        if ($count > $limit) {
            $response = response()->json([
                'code' => 'rate_limited',
                'message' => 'Muitas tentativas. Tente novamente em instantes.',
            ], 429, ['Retry-After' => (string) $ttl]);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            abort($response);
        }
    }
}
