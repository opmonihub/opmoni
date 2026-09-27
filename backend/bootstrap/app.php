<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tráfego chega Traefik -> nginx -> PHP-FPM. O nginx repassa os
        // X-Forwarded-* do Traefik intactos, então confiar em todos os proxies é
        // o que faz isSecure()/host/port refletirem o HTTPS público
        // (cookie de sessão secure, URL::current, redirect do Traefik).
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'super_admin' => EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
