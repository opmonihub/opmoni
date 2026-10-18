<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Support\ExternalHttpBudget;
use App\Support\HttpPkcs12ClientOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Fresh authentication only. Tokens and provider bodies never leave this service. */
final class SerproDiagnosticAuthenticator
{
    public function __construct(private SerproCertificateMaterializer $materializer) {}

    public function authenticate(SerproCredentialSnapshot $snapshot): void
    {
        $url = (string) config('integra-contador.auth_url');
        $parts = parse_url($url);
        $timeout = (float) config('integra-contador.diagnostic_timeout', 8);
        $connectTimeout = (float) config('integra-contador.diagnostic_connect_timeout', 3);
        $proxyTimeout = (float) config('integra-contador.diagnostic_proxy_timeout', 15);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', config('integra-contador.auth_trusted_hosts', []), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! is_finite($connectTimeout) || ! is_finite($timeout) || ! is_finite($proxyTimeout)
            || $connectTimeout <= 0 || $connectTimeout > 3 || $timeout <= 0 || $timeout > 8 || $connectTimeout > $timeout
            || $proxyTimeout <= $timeout || $proxyTimeout > 15 || $proxyTimeout + 5 >= (float) config('access.lock_seconds', 30)) {
            throw new SerproException('Configuração segura de autenticação indisponível.', SerproFailure::NotSent, 0);
        }
        $budget = ExternalHttpBudget::fromRequest(request());
        $budget?->assertAvailable();
        $this->materializer->withCertificate($snapshot, function (string $path) use ($snapshot, $url, $timeout, $connectTimeout, $budget): void {
            try {
                $budget?->beforeSend();
                $options = $budget?->options($timeout) ?? ['timeout' => $timeout, 'connect_timeout' => $connectTimeout, 'allow_redirects' => false];
                $options['connect_timeout'] = min($connectTimeout, $options['connect_timeout']);
                $response = Http::asForm()->withBasicAuth($snapshot->consumerKey(), $snapshot->consumerSecret())
                    ->withHeaders(['Role-Type' => 'TERCEIROS'])
                    ->withOptions(HttpPkcs12ClientOptions::forPath($path, $snapshot->certificatePassword()))
                    ->withOptions(['verify' => true, ...$options])
                    ->post($url, ['grant_type' => 'client_credentials']);
            } catch (ConnectionException) {
                throw new SerproException('O serviço de autenticação está indisponível.', SerproFailure::Upstream, 503);
            }
            $budget?->assertAvailable();
            if (! $response->successful()) {
                $status = $response->status();
                throw new SerproException('A autenticação não pôde ser concluída.', match (true) {
                    $status === 429 => SerproFailure::Throttled,
                    $status >= 500 || $status < 400 => SerproFailure::Upstream,
                    default => SerproFailure::DoNotRetry,
                }, $status);
            }
            foreach (['access_token', 'jwt_token'] as $field) {
                if (! is_string($response->json($field)) || trim($response->json($field)) === '') {
                    throw new SerproException('Resposta de autenticação incompleta.', SerproFailure::Upstream, 502);
                }
            }
        });
    }
}
