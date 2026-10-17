<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class AccessSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $revoked = Cache::get('revoked:'.hash('sha256', $session->getId()));
        if (($session->has(Auth::guard('web')->getName()) && (! $session->has('access_deadline') || $session->get('access_authenticated') !== true)) || $revoked || ($session->has('access_deadline') && (int) $session->get('access_deadline') <= time())) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
        }
        if (! $session->has('access_deadline')) {
            $session->put(['access_deadline' => time() + 1800, 'access_authenticated' => false]);
        }
        $name = $request->route()->getName();
        if ((str_starts_with((string) $name, 'access.admin-') || in_array($name, ['access.me', 'access.logout', 'access.switch'], true)) && ! Auth::guard('web')->check()) {
            abort(401);
        }

        $ip = (string) $request->attributes->get('access.client_ip');
        $limit = match ($name) {
            'access.register', 'erp.register' => 5,'access.login','access.activate' => 10,'access.csrf','access.registration-status','access.activation-status','erp.registration-status' => 30,default => null
        };
        if ($limit) {
            $this->budget($name.':ip:'.$ip, $limit);
            if ($name === 'access.login') {
                $this->budget('login:email:'.hash('sha256', mb_strtolower(trim(is_string($request->input('email')) ? $request->input('email') : ''))), 10);
            }
        }
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            abort_unless($request->header('Origin') === config('access.origin') && is_string($request->header('X-CSRF-Token')) && hash_equals($session->token(), $request->header('X-CSRF-Token')), 403, 'Solicitação não autorizada');
        }

        return $next($request);
    }

    private function budget(string $key, int $limit): void
    {
        $key = 'access-limit:'.hash('sha256', $key);
        if (config('cache.default') === 'redis') {
            $connection = Redis::connection(config('cache.stores.redis.connection', 'cache'));
            $key = config('cache.prefix').$key;
            $result = $connection->eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],60) end; return {n,redis.call('TTL',KEYS[1])}", 1, $key);
            $count = (int) $result[0];
            $ttl = max(1, (int) $result[1]);
        } else {
            $count = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $count, 60);
            $ttl = 60;
        } if ($count > $limit) {
            abort(response()->json(['code' => 'rate_limited', 'message' => 'Muitas tentativas. Tente novamente em instantes.'], 429, ['Retry-After' => (string) $ttl]));
        }
    }
}
