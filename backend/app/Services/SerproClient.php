<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use App\Support\HttpPkcs12ClientOptions;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class SerproClient
{
    /**
     * O serviço que recebe o termo, e o sistema a que ele pertence.
     *
     * Os dois são literais do provedor, e o `idSistema` **não** é o mesmo
     * que o das demais chamadas: as de leitura usam o sistema do serviço
     * (`REGIMEAPURACAO`, `SITFIS`), e o termo é a sessão que autoriza todas
     * elas, o que o provedor publica em `AUTENTICAPROCURADOR`. Deduzir um do
     * outro produziria um envelope que o gateway recusa por um motivo que nada
     * na resposta nomearia.
     */
    private const SISTEMA_TERMO = 'AUTENTICAPROCURADOR';

    private const SERVICO_TERMO = 'ENVIOXMLASSINADO81';

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
        return $this->transporte($connection, $procuradorToken, $tag, $certificatePath, function (PendingRequest $http) use ($connection, $idSistema, $idServico, $dados, $autor, $contribuinte, $service): Response {
            return $http->post($this->baseUrl().'/'.$service['path'], $this->envelope->build(
                $connection->contratante_numero,
                (int) $connection->contratante_tipo,
                $autor,
                $contribuinte,
                $idSistema,
                $idServico,
                $service['versaoSistema'],
                $dados,
            ));
        });
    }

    /**
     * O transporte de toda chamada de serviço: o par de tokens, os cabeçalhos
     * que o gateway exige, o mTLS com o certificado do contratante e o
     * limite de tempo.
     *
     * **O par de tokens é lido aqui, por tentativa, e não por chamada.** É o
     * que faz a repetição em `401` funcionar: `send()` descarta o par, chama
     * `request()` de novo, e a segunda leitura é a nova. Levar o par para fora
     * de `request()` e injetá-lo aqui devolveria o mesmo token velho na
     * segunda tentativa, que é exatamente o que o `401` diz para não usar.
     *
     * **Por que o `curl` de mTLS mora aqui e não em `SerproTokenProvider`.**
     * A autenticação e a chamada de serviço autenticam de maneiras diferentes
     * — `client_credentials` com basic auth contra `auth_url`, e bearer contra
     * o gateway —, e as duas leem a mesma senha do certificado. O que as duas
     * compartilham é isto: um PFX materializado em disco efêmero e as três
     * opções de `curl` que o Guzzle exige para apresentá-lo. Duplicar essas
     * três linhas seria a forma mais barata de um dia o provedor exigir
     * `CURLOPT_SSLKEY` e uma delas continuar mandando o certificado errado.
     *
     * @param  Closure(PendingRequest): Response  $post
     *
     * @throws SerproException
     */
    private function transporte(
        SerproConnection $connection,
        ?string $procuradorToken,
        string $tag,
        string $certificatePath,
        Closure $post,
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
            return $post(Http::acceptJson()
                ->withHeaders($headers)
                ->withToken($pair->accessToken())
                ->withOptions(HttpPkcs12ClientOptions::forPath(
                    $certificatePath,
                    $connection->certificatePassword(),
                ))
                ->timeout((int) config('integra-contador.timeout', 25)));
        } catch (ConnectionException) {
            // A tag existe — foi construída em `call()` e viajou no cabeçalho
            // — e a exceção a carrega para o `serpro_calls` da tentativa
            // continuar atrelado ao que o gateway recebeu.
            throw new SerproException(
                'O Integra Contador está indisponível.',
                SerproFailure::Upstream,
                503,
                null,
                null,
                $tag,
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

        // O provedor responde `503` com `[Sucesso-Sitfis-SC01]` quando o
        // relatório está pronto em cache — não é falha, é o contrato do
        // serviço, e o envelope traz `dados.tempoEspera` com o tempo a
        // aguardar. Quem decide o que fazer com ele é quem chamou
        // (`SerproSitfisSequence`).
        if ($failure === SerproFailure::Upstream
            && $response->status() === 503
            && SerproException::normalizeProviderCode($providerCode) === 'Sucesso-Sitfis-SC01') {
            return new SerproResult(
                $envelope['status'],
                $envelope['dados'],
                $envelope['mensagens'],
                $envelope['response_id'],
                $tag,
            );
        }

        if ($failure !== SerproFailure::Success) {
            throw new SerproException(
                $this->failureMessage($envelope['mensagens'], $failure, $payload),
                $failure,
                $response->status(),
                $providerCode === '' ? null : $providerCode,
                $envelope['response_id'],
                $tag,
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
            return SerproException::normalizeProviderCode($codigo);
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
        if (! array_key_exists('status', $payload)) {
            return $failure->label();
        }

        $texto = $mensagens[0]['texto'] ?? '';
        $codigo = $mensagens[0]['codigo'] ?? '';

        if ($texto !== '' && ! $this->textoDeSucessoGenerico($texto, $codigo)) {
            return $texto;
        }

        return $failure->label();
    }

    /**
     * Em alguns erros o envelope ainda traz a frase de sucesso genérica — ou só
     * ela — e subir isso como mensagem da exceção confunde operador e log.
     */
    private function textoDeSucessoGenerico(string $texto, string $codigo): bool
    {
        if (stripos($texto, 'efetuada com sucesso') !== false) {
            return true;
        }

        $codigoNormalizado = SerproException::normalizeProviderCode($codigo);

        return str_starts_with($codigoNormalizado, 'Sucesso');
    }

    private function baseUrl(): string
    {
        return (string) config('integra-contador.gateway_url');
    }

    /**
     * Envia o termo de autorização assinado ao serviço gratuito de apoio e
     * devolve **cabeçalhos e corpo**, sem interpretar nada.
     *
     * **Por que este método existe e não é uma opção de `call()`.** A
     * renovação reenvia o documento já assinado e o provedor responde `304`,
     * que é sucesso sem envelope: corpo vazio, o token no `etag` e a validade
     * no `expires`. `call()` classifica o `status` pelo provedor e classifica
     * `304` como recusa, o que é correto para todo outro serviço e errado
     * para este — a resposta de `304` não é um erro, é o atalho que evita
     * re-assinar. Tratar isso como falha do provedor faria a renovação diária
     * falhar todo dia para todos os escritórios, e o defeito apareceria como
     * "o SERPRO está fora", que é a leitura mais errada possível.
     *
     * Por isso este método devolve o **status cru**, o `etag`, o `expires`, o
     * `codigo` da recusa e o `dados` já decodificado, e deixa a decisão para
     * o `SerproTermManager`: são dois estados de negócio diferentes — o
     * provedor aceitou e emitiu token, ou o provedor aceitou e o token estava
     * em cache —, e quem sabe o que fazer com cada um é quem escreve o estado
     * do termo.
     *
     * **Duas chaves a mais do que o array do plano 02, e cada uma por um
     * motivo que o próprio plano exige.** `expires` é onde o `304` traz a
     * validade do token — a documentação do provedor é explícita e o passo 4
     * do plano pede "salva novo vencimento" no caminho do `304`; e `codigo` é
     * o código de recusa do envelope, que a spec chama de "a causa" e que é
     * a única parte da resposta que é seguro levar para o banco e para a
     * tela. Um e outro faltando transformaria o plano em um plano que grava
     * um `304` sem validade e uma recusa sem causa.
     *
     * **O que ele não faz, e é o que o documento do provedor diz.** O termo
     * vai em `dados.xml` **codificado em base64** — a documentação do serviço
     * é explícita e o exemplo do provedor traz a string codificada dentro do
     * envelope. E o `autor` é o escritório: quem assina é o Autor Pedido de
     * Dados, e o `contribuinte` é o mesmo documento porque o termo autoriza o
     * escritório a agir em nome de si mesmo, e não há um terceiro sujeito
     * nesta requisição. Fonte: `…/autenticaprocurador/servicos/envio_de_xml_assinado/`,
     * lida em 2026-09-28.
     *
     * **`codigo` é `string` e não `?string`, e a ausência vira string vazia.**
     * O envelope pode não trazer mensagem nenhuma — o `304` não tem envelope, e
     * uma resposta de sucesso sem `mensagens` também não —, e o que volta
     * nesse caso é `''`. Um `null` obrigaria cada leitor a distinguir "não
     * veio" de "veio vazio" para chegar ao mesmo lugar, e `tratarRecusa()` usa
     * a string vazia como "não informado" tanto na frase quanto na exceção.
     *
     * @return array{status: int, etag: ?string, expires: ?string, codigo: string, dados: mixed}
     *
     * @throws SerproException
     */
    public function submitTerm(string $signedXml, string $author): array
    {
        $connection = SerproConnection::current();

        if ($connection === null) {
            throw new SerproException(
                'Conexão com o Integra Contador não configurada.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        $connection->assertIdentity();

        $service = $this->service(self::SERVICO_TERMO);
        $tag = $this->tag->build($author, $author, 1);

        $response = $this->materializer->withCertificate(
            $connection,
            fn (string $path): Response => $this->termRequest($connection, $service, $tag, $path, $signedXml, $author),
        );

        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->materializer->withCertificate(
                $connection,
                fn (string $path): Response => $this->termRequest($connection, $service, $tag, $path, $signedXml, $author),
            );
        }

        // O envelope do `304` não existe: o corpo é vazio por definição, e o
        // `parse()` dele devolveria um `status` zero e um `dados` nulo que
        // pareceriam "o provedor não disse". O que o `304` traz está nos
        // cabeçalhos, e é isso que volta.
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $envelope = $this->envelope->parse($payload);

        return [
            'status' => $response->status(),
            'etag' => $response->header('ETag'),
            'expires' => $response->header('Expires'),
            'codigo' => $envelope['mensagens'][0]['codigo'] ?? '',
            'dados' => $envelope['dados'],
        ];
    }

    /**
     * A requisição do termo, que é `call()` sem a classificação de falha.
     *
     * O mTLS, o par de tokens e o envelope são os de `transporte()` — o
     * mesmo caminho, com a mesma senha do certificado e o mesmo
     * `X-Request-Tag` —, e a única diferença é que o resultado não passa por
     * `interpret()`.
     */
    private function termRequest(
        SerproConnection $connection,
        array $service,
        string $tag,
        string $certificatePath,
        string $signedXml,
        string $author,
    ): Response {
        return $this->transporte($connection, null, $tag, $certificatePath, function (PendingRequest $http) use ($connection, $service, $signedXml, $author): Response {
            return $http->post($this->baseUrl().'/'.$service['path'], $this->envelope->build(
                $connection->contratante_numero,
                (int) $connection->contratante_tipo,
                $author,
                $author,
                self::SISTEMA_TERMO,
                self::SERVICO_TERMO,
                $service['versaoSistema'],
                ['xml' => base64_encode($signedXml)],
            ));
        });
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
