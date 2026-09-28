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

        // Antes de materializar o PFX e antes de autenticar: o `contratante` do
        // envelope tem de ser o CNPJ do certificado, e essa é a última hora em
        // que a divergência é um defeito de configuração nomeado, e não um
        // `403` do provedor que ninguém consegue distinguir de senha errada.
        $connection->assertIdentity();

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
        $payload = is_array($payload) ? $payload : [];
        $envelope = $this->envelope->parse($payload);
        $providerCode = $this->providerCode($envelope['mensagens'], $payload);
        $failure = SerproException::classify($response->status(), $providerCode);

        if ($failure !== SerproFailure::Success) {
            throw new SerproException(
                $this->failureMessage($envelope['mensagens'], $failure, $payload),
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

    /**
     * O código da falha, de onde o provedor o*pôde* mandar.
     *
     * A falha da aplicação vem em `mensagens`; a do gateway não tem envelope
     * nenhum e traz só o `code`. O texto que vem junto — `message` e
     * `description` — é o que o provedor escreveu sobre a requisição que
     * fizemos, e a requisição carrega o token e o documento do cliente. O que
     * sobe é o código, que é o que classifica a falha; o texto não sai daqui.
     *
     * `mensagens` só é lido quando o corpo tem envelope, e o sinal é o `status`
     * de chave. Nenhuma forma observada do provedor traz `mensagens` num erro
     * de gateway — a fixture `gateway-429.json` não tem —, mas a leitura sem
     * essa guarda significaria que um corpo de gateway que por acaso trouxesse
     * `mensagens` teria o `texto` livre dele subindo para
     * `SerproException::getMessage()` e de lá para o log, que é o caminho que
     * `test_um_erro_de_gateway_nao_devolve_o_texto_do_provedor` existe para
     * fechar. Um `status` presente é o que distingue as duas famílias: o
     * envelope sempre o traz, e o gateway nunca traz envelope.
     *
     * @param  list<array{codigo: string, texto: string}>  $mensagens
     * @param  array<string, mixed>  $payload
     */
    private function providerCode(array $mensagens, array $payload): string
    {
        $temEnvelope = array_key_exists('status', $payload);
        $codigo = $temEnvelope ? ($mensagens[0]['codigo'] ?? '') : '';
        $gateway = $payload['code'] ?? null;

        if ($codigo !== '') {
            return $codigo;
        }

        return is_scalar($gateway) ? (string) $gateway : '';
    }

    /**
     * A mensagem da exceção é o texto que o serviço documentou ou o rótulo da
     * falha — nunca o texto livre do gateway, e nunca vazia, que no log seria
     * uma `SerproException` sem dizer nada.
     *
     * O texto do provedor só entra quando **há envelope**, pelo mesmo sinal e
     * pelo mesmo motivo de `providerCode()`: sem envelope, `mensagens` não é a
     * falha da aplicação, e um `texto` que apareceu ali é texto livre de quem
     * respondeu — que é o que a requisição com token e documento do cliente não
     * pode deixar subir para o log.
     *
     * @param  list<array{codigo: string, texto: string}>  $mensagens
     */
    private function failureMessage(array $mensagens, SerproFailure $failure, array $payload): string
    {
        $texto = array_key_exists('status', $payload) ? ($mensagens[0]['texto'] ?? '') : '';

        return $texto !== '' ? $texto : $failure->label();
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
