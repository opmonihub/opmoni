<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuperAdmin() !== true) {
            abort(403, 'Acesso restrito ao super_admin.');
        }

        return $next($request);
    }
}
