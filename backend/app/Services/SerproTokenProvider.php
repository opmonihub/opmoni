<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Illuminate\Contracts\Encryption\DecryptException;
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

    /**
     * Verificação sob demanda: descarta o par guardado e autentica de novo.
     *
     * Sem `forget()` o par em cache responderia "está funcionando" com uma
     * autenticação de meia hora atrás. E o que fica em cache depois é o par
     * recém-emitido, então o teste não custa uma autenticação à sincronização
     * seguinte. Nenhum serviço é chamado aqui — a pergunta é sobre a credencial,
     * e um gateway que responde bem não diz nada sobre ela.
     */
    public function verify(): void
    {
        $this->forget();
        $this->pair();
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

    /**
     * O segredo é lido aqui, e não em frente ao `curl`.
     *
     * Um cifrado guardado que não abre com a chave de aplicação atual é uma
     * falha nomeada da credencial, e não um `DecryptException` subindo de dentro
     * de uma requisição HTTP para virar `500` no consumidor. A mensagem não
     * repete o erro do OpenSSL nem o valor guardado.
     *
     * @throws SerproException
     */
    private function secretOf(SerproConnection $connection): string
    {
        try {
            return $connection->consumerSecret();
        } catch (DecryptException) {
            throw new SerproException(
                'O segredo da credencial guardada não pôde ser lido: o conteúdo guardado não abre com a chave de aplicação atual.',
                SerproFailure::NotSent,
                0,
            );
        }
    }

    private function request(SerproConnection $connection, string $certificatePath): SerproTokenPair
    {
        $secret = $this->secretOf($connection);

        try {
            $response = Http::asForm()
                ->withBasicAuth($connection->consumer_key, $secret)
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
            // O `4xx` recusa o que foi enviado e o `5xx` diz que o serviço não
            // deu conta: são defeitos de ações opostas — corrigir a credencial ou
            // esperar o provedor — e quem precisa decidir entre as duas é quem
            // vai tratar a falha.
            throw $response->serverError()
                ? new SerproException(
                    'O serviço de autenticação do Integra Contador está indisponível.',
                    SerproFailure::Upstream,
                    $response->status(),
                )
                : new SerproException(
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
