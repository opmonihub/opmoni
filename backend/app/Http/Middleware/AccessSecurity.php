<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccessSession;
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
        $user = Auth::guard('web')->user();
        $revisionMismatch = false;
        $capturedRevision = null;
        if ($user !== null) {
            $currentRevision = User::query()->whereKey($user->getAuthIdentifier())->value('access_auth_revision');
            $capturedRevision = $session->get('access_auth_revision', 0);
            $revisionMismatch = $currentRevision === null || ! is_int($capturedRevision)
                || $capturedRevision < 0 || $capturedRevision !== (int) $currentRevision;
        }
        $authenticationSessionInvalid = $session->has(Auth::guard('web')->getName())
            && (! $session->has('access_deadline') || $session->get('access_authenticated') !== true);
        $deadlineExpired = $session->has('access_deadline') && (int) $session->get('access_deadline') <= time();
        $completedRecoveryContinuation = null;
        if ($revisionMismatch && ! $revoked && ! $authenticationSessionInvalid && ! $deadlineExpired
            && $user !== null && is_int($capturedRevision) && $capturedRevision >= 0) {
            $completedRecoveryContinuation = AccessSession::preservableCompletedRecoveryForRevisionMismatch(
                $request,
                (int) $user->getAuthIdentifier(),
                $capturedRevision,
            );
        }
        if ($authenticationSessionInvalid || $revoked || $revisionMismatch || $deadlineExpired) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
            AccessSession::restoreCompletedRecoveryAfterRevisionMismatch($request, $completedRecoveryContinuation);
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
            'access.register', 'erp.register', 'erp.trials.register' => 5,
            'access.login', 'access.activate' => 10,
            'access.csrf', 'access.registration-status', 'access.activation-status', 'erp.registration-status', 'erp.trials.show', 'erp.trials.current',
            'office-erp-member-invitations.resolve', 'office-erp-member-invitations.current',
            'office-erp-password-recovery.resolve', 'office-erp-password-recovery.current' => 30,
            default => null,
        };
        if ($limit) {
            $publicReceiver = in_array($name, [
                'office-erp-member-invitations.resolve', 'office-erp-member-invitations.current',
                'office-erp-password-recovery.resolve', 'office-erp-password-recovery.current',
            ], true);
            $this->budget($publicReceiver ? 'access.public-receiver:ip:'.$ip : $name.':ip:'.$ip, $limit, $publicReceiver);
            if ($name === 'erp.trials.register' && $request->user() !== null) {
                $this->budget($name.':actor:'.(int) $request->user()->getAuthIdentifier(), $limit);
            }
            if ($name === 'access.login') {
                $this->budget('login:email:'.hash('sha256', mb_strtolower(trim(is_string($request->input('email')) ? $request->input('email') : ''))), 10);
            }
        }
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            abort_unless($request->header('Origin') === config('access.origin') && is_string($request->header('X-CSRF-Token')) && hash_equals($session->token(), $request->header('X-CSRF-Token')), 403, 'Solicitação não autorizada');
        }

        $response = $next($request);
        if (in_array($name, ['office-erp-member-invitations.resolve', 'office-erp-member-invitations.current', 'office-erp-password-recovery.resolve', 'office-erp-password-recovery.current', 'office-erp-password-recovery.complete'], true)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }

    private function budget(string $key, int $limit, bool $private = false): void
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
            $response = response()->json(['code' => 'rate_limited', 'message' => 'Muitas tentativas. Tente novamente em instantes.'], 429, ['Retry-After' => (string) $ttl]);
            if ($private) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Referrer-Policy', 'no-referrer');
            }
            abort($response);
        }
    }
}
