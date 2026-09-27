<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class SerproClient
{
    public function __construct(
        private SerproEnvelope $envelope,
        private SerproRequestTag $tag,
        private SerproTokenProvider $tokens,
        private SerproCertificateMaterializer $materializer,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public function call(
        string $idSistema,
        string $idServico,
        array $dados,
        string $autor,
        string $contribuinte,
        ?string $procuradorToken = null,
        int $serviceSequence = 1,
    ): SerproResult {
        $connection = SerproConnection::current();

        if ($connection === null) {
            throw new SerproException(
                'Conexão com o Integra Contador não configurada.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        $service = $this->service($idServico);
        $tag = $this->tag->build($autor, $contribuinte, $serviceSequence);

        return $this->materializer->withCertificate(
            $connection,
            fn (string $path): SerproResult => $this->send(
                $connection,
                $idSistema,
                $idServico,
                $dados,
                $autor,
                $contribuinte,
                $procuradorToken,
                $tag,
                $service,
                $path,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array{path: string, versaoSistema: string}  $service
     */
    private function send(
        SerproConnection $connection,
        string $idSistema,
        string $idServico,
        array $dados,
        string $autor,
        string $contribuinte,
        ?string $procuradorToken,
        string $tag,
        array $service,
        string $certificatePath,
    ): SerproResult {
        $response = $this->request($connection, $idSistema, $idServico, $dados, $autor, $contribuinte, $procuradorToken, $tag, $service, $certificatePath);

        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->request($connection, $idSistema, $idServico, $dados, $autor, $contribuinte, $procuradorToken, $tag, $service, $certificatePath);
        }

        return $this->interpret($response, $tag);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array{path: string, versaoSistema: string}  $service
     */
    private function request(
        SerproConnection $connection,
        string $idSistema,
        string $idServico,
        array $dados,
        string $autor,
        string $contribuinte,
        ?string $procuradorToken,
        string $tag,
        array $service,
        string $certificatePath,
    ): Response {
        $pair = $this->tokens->pair();

        $headers = [
            'jwt_token' => $pair->jwtToken(),
            'X-Request-Tag' => $tag,
        ];

        if ($procuradorToken !== null) {
            $headers['autenticar_procurador_token'] = $procuradorToken;
        }

        try {
            return Http::acceptJson()
                ->withHeaders($headers)
                ->withToken($pair->accessToken())
                ->withOptions(['curl' => [
                    CURLOPT_SSLCERT => $certificatePath,
                    CURLOPT_SSLCERTPASSWD => $connection->certificatePassword() ?? '',
                    CURLOPT_SSLCERTTYPE => 'P12',
                ]])
                ->timeout((int) config('integra-contador.timeout', 25))
                ->post($this->baseUrl().'/'.$service['path'], $this->envelope->build(
                    $connection->contratante_numero,
                    (int) $connection->contratante_tipo,
                    $autor,
                    $contribuinte,
                    $idSistema,
                    $idServico,
                    $service['versaoSistema'],
                    $dados,
                ));
        } catch (ConnectionException) {
            throw new SerproException(
                'O Integra Contador está indisponível.',
                SerproFailure::Upstream,
                503,
            );
        }
    }

    private function interpret(Response $response, string $tag): SerproResult
    {
        $payload = $response->json();
        $envelope = $this->envelope->parse(is_array($payload) ? $payload : []);
        $providerCode = $envelope['mensagens'][0]['codigo'] ?? '';
        $failure = SerproException::classify($response->status(), $providerCode);

        if ($failure !== SerproFailure::Success) {
            throw new SerproException(
                $envelope['mensagens'][0]['texto'] ?? $failure->label(),
                $failure,
                $response->status(),
                $providerCode === '' ? null : $providerCode,
                $envelope['response_id'],
            );
        }

        return new SerproResult(
            $envelope['status'],
            $envelope['dados'],
            $envelope['mensagens'],
            $envelope['response_id'],
            $tag,
        );
    }

    private function baseUrl(): string
    {
        return (string) config('integra-contador.gateway_url');
    }

    /**
     * @return array{path: string, versaoSistema: string}
     */
    private function service(string $idServico): array
    {
        /** @var array<string, array{path: string, versaoSistema: string}> $services */
        $services = (array) config('integra-contador.services', []);

        if (! isset($services[$idServico])) {
            throw new SerproException(
                "Serviço {$idServico} não está mapeado no catálogo do Integra Contador.",
                SerproFailure::DoNotRetry,
                0,
            );
        }

        return $services[$idServico];
    }
}
