<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustedIngress
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('access.ingress_key');
        $ip = $request->header('X-Opmoni-Client-IP');
        $proto = $request->header('X-Opmoni-Client-Proto');
        abort_unless($key !== '' && hash_equals($key, (string) $request->header('X-Opmoni-Ingress-Key')) && filter_var($ip, FILTER_VALIDATE_IP) && in_array($proto, ['http', 'https']), 403, 'Origem não autorizada');
        $request->attributes->set('access.client_ip', $ip);
        $request->server->set('HTTPS', $proto === 'https' ? 'on' : 'off');
        config(['session.secure' => $proto === 'https', 'session.domain' => null, 'session.path' => '/', 'session.http_only' => true, 'session.same_site' => 'lax', 'session.cookie' => $proto === 'https' ? '__Host-opmoni-session' : 'opmoni-session']);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
