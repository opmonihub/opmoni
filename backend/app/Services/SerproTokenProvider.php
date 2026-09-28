<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SerproTokenProvider
{
    private const CACHE_KEY = 'serpro:token-pair';

    public function __construct(private SerproCertificateMaterializer $materializer) {}

    public function pair(): SerproTokenPair
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof SerproTokenPair) {
            return $cached;
        }

        return $this->authenticate();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function authenticate(): SerproTokenPair
    {
        $connection = SerproConnection::current();

        if ($connection === null) {
            throw new SerproException(
                'Conexão com o Integra Contador não configurada.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        // A identidade é conferida antes de qualquer autenticação: pedir token
        // com um certificado que não é o do contratante gasta uma chamada
        // recusada para descobrir o que já se sabe localmente.
        $connection->assertIdentity();

        return $this->materializer->withCertificate(
            $connection,
            fn (string $path): SerproTokenPair => $this->request($connection, $path),
        );
    }

    private function request(SerproConnection $connection, string $certificatePath): SerproTokenPair
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($connection->consumer_key, $connection->consumerSecret())
                ->withHeaders(['Role-Type' => 'TERCEIROS'])
                ->withOptions(['curl' => [
                    CURLOPT_SSLCERT => $certificatePath,
                    CURLOPT_SSLCERTPASSWD => $connection->certificatePassword() ?? '',
                    CURLOPT_SSLCERTTYPE => 'P12',
                ]])
                ->timeout((int) config('integra-contador.timeout', 25))
                ->post((string) config('integra-contador.auth_url'), ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new SerproException(
                'O serviço de autenticação do Integra Contador está indisponível.',
                SerproFailure::Upstream,
                503,
                null,
                null,
            );
        }

        if ($response->failed()) {
            throw new SerproException(
                'A credencial do Integra Contador foi recusada.',
                SerproFailure::DoNotRetry,
                $response->status(),
            );
        }

        $payload = $response->json();
        $accessToken = (string) data_get($payload, 'access_token', '');
        $jwtToken = (string) data_get($payload, 'jwt_token', '');

        if ($accessToken === '' || $jwtToken === '') {
            throw new SerproException(
                'A resposta de autenticação não trouxe os dois tokens exigidos.',
                SerproFailure::Upstream,
                502,
            );
        }

        $pair = new SerproTokenPair(
            $accessToken,
            $jwtToken,
            (int) data_get($payload, 'expires_in', 0),
        );

        Cache::put(self::CACHE_KEY, $pair, $pair->ttl());

        return $pair;
    }
}
