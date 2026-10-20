<?php

use App\Http\Middleware\LimitPlatformCertificateUpload;
use App\Http\Middleware\ResolveAccount;
use App\Http\Middleware\TrustedIngress;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LimitPlatformCertificateUpload::class);
        $middleware->prependToPriorityList(EncryptCookies::class, TrustedIngress::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveAccount::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('api/v1/serpro/connection', 'api/v1/admin/accounts/*/contracting-certificate')]);
        $middleware->trimStrings(except: ['password', 'password_confirmation', 'token', 'barcode', 'variants.*.barcode']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($error instanceof HttpResponseException) {
                return $error->getResponse();
            }
            $status = match (true) {
                $error instanceof ValidationException => 422,
                $error instanceof AuthenticationException => 401,
                $error instanceof ModelNotFoundException => 404,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 503,
            };
            $message = match ($status) {
                429 => 'Muitas tentativas. Tente novamente em instantes.', 413 => 'Solicitação muito grande.', 401 => 'Sessão expirada. Entre novamente.', 403 => 'Acesso negado', 404 => 'Registro não encontrado', 422 => 'Verifique os campos informados', 409 => 'Operação indisponível neste estado', default => 'Serviço temporariamente indisponível. Tente novamente.'
            };
            $body = ['code' => match ($status) {
                429 => 'rate_limited',413 => 'payload_too_large',401 => 'unauthenticated',403 => 'forbidden',404 => 'not_found',422 => 'validation_error',409 => 'conflict',default => 'service_unavailable'
            }, 'message' => $message];
            if ($error instanceof ValidationException) {
                $body['errors'] = $error->errors();
                if (($body['errors']['email'][0] ?? null) === 'Email ou senha inválidos') {
                    $body['code'] = 'invalid_credentials';
                    $body['message'] = 'Email ou senha inválidos';
                }
                if (str_contains((string) ($body['errors']['email'][0] ?? ''), 'Faça login')) {
                    $body['code'] = 'login_required';
                    $body['message'] = (string) $body['errors']['email'][0];
                }
            }

            return response()->json($body, $status, ['Cache-Control' => 'no-store', ...($error instanceof HttpExceptionInterface ? $error->getHeaders() : [])]);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
