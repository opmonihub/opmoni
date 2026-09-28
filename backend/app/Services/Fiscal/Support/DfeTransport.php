<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalFailure;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A parte da distribuição que é a mesma em todos os serviços de DF-e: a
 * consulta SOAP com o certificado do cliente no transporte, a checagem do corpo
 * contra o XSD local e a leitura da resposta em `DfeResponse`.
 *
 * A classe é sem estado e não monta corpo nem escolhe serviço. O `endpoint`
 * chega pronto de quem chamou — namespace, versão, método e ação SOAP são
 * parâmetros do serviço, não da chamada — e o corpo chega montado, porque o
 * corpo também é o que distingue um serviço do outro. O que é comum vive
 * aqui, e é o que permite ao conector de CT-e falar com o fisco pelo mesmo
 * caminho, pelas mesmas duas portas (`distNSU` e `consNSU`) e com a mesma
 * autenticação.
 *
 * **O que ela fixa, e é uma exceção, não agnosticismo:** o elemento do payload
 * é `distDFeInt` e é ele que o schema local valida (`PAYLOAD_ELEMENT`, usado em
 * dois lugares abaixo). Os dois serviços de distribuição deste módulo — NF-e e
 * CT-e — nomeiam o payload igual, e é por isso que a constante serve aos dois;
 * a resposta também é `retDistDFeInt` nos dois, e é o que o parser encontra por
 * nome local. Um terceiro serviço com outro nome de payload não é coberto por
 * esta classe, e precisaria do nome como parâmetro, do mesmo modo que o serviço
 * e a versão do schema já são.
 *
 * Quatro regras que este arquivo carrega desde o conector de NF-e:
 *
 * 1. **A requisição não é assinada.** O XSD local recusa assinatura injetada
 *    antes de qualquer byte — os dois serviços têm schema fechado, sem `xs:any` —,
 *    e a autenticação é o certificado A1 do cliente no transporte. Não existe
 *    XMLDSig aqui nem em qualquer outro arquivo do módulo. O `cStat 215` que o
 *    serviço de NF-e devolve a um corpo assinado é verificado **dele**; para o
 *    serviço de CT-e é hipótese, e é uma das coisas que o canário de um cliente
 *    vai conferir.
 * 2. **Nada é manifestado.** A distribuição consulta e lê. O `210200` é um
 *    ato legal que bloquearia o cancelamento do emissor, e nenhum caminho de
 *    código deste módulo o envia.
 * 3. **A verificação do servidor continua ligada**, apoiada no bundle
 *    versionado da ICP-Brasil, e a senha do certificado vive só no escopo da
 *    opção do `curl`.
 * 4. **A resposta do serviço não é registrada.** Nem o XML bruto, nem o
 *    `docZip`, nem o `detail` do fault — que é onde um serviço ecoa o pedido.
 *    O texto do fault é condensado antes de virar mensagem de exceção.
 */
final class DfeTransport
{
    /**
     * Tempo de conexão é o do transporte, não o do fisco: um lote de 50
     * documentos leva de 1 a 3 MB com mTLS, e a janela do worker é de 120
     * segundos. Esperar mais que isso por um TCP handshake só rouba o tempo do
     * resto do lote.
     */
    private const CONNECT_TIMEOUT_SECONDS = 15;

    /**
     * O elemento que embrulha o payload dentro do envelope, e o nome do schema
     * local que valida esse payload. Os dois serviços de distribuição deste
     * módulo usam o mesmo nome, e o cabeçalho da classe diz por que isso é uma
     * exceção declarada e não um esquecimento.
     */
    private const PAYLOAD_ELEMENT = 'distDFeInt';

    /**
     * O que o serviço diz sobre a própria falha, e o máximo que vai para a
     * mensagem. A condição cabe em uma linha; um `faultstring` de serviço em
     * manutenção é o que estoura isso.
     */
    private const FAULT_TEXT_LIMIT = 300;

    public function __construct(
        private DfeResponseParser $parser,
        private FiscalXmlValidator $validator,
        private ClientCertificateMaterializer $materializer,
    ) {}

    /**
     * `$endpoint` é o bloco de `config('fiscal.endpoints')` do serviço e `$body`
     * é o envelope que ele montou: esta classe não monta corpo, porque o corpo
     * é o que distingue um serviço do outro.
     *
     * A requisição vai sem SOAP header e o corpo é checado contra o XSD local
     * antes de qualquer byte na rede — inclusive o corpo que o chamador
     * reescreveu, que é o da consulta por chave: é a reescrita que o validador
     * existe para pegar.
     *
     * @param  array<string, string>  $endpoint
     */
    public function request(Client $client, array $endpoint, string $body): DfeResponse
    {
        // O bloco é lido antes de qualquer outra coisa, e não por organização: um
        // parâmetro faltando é defeito de configuração, e materializar
        // certificado para descobrir isso depois seria trabalho de disco e de
        // senha por um erro que o array já denunciava.
        $schemaService = $this->parameterOf($endpoint, 'xsd_service');
        $version = $this->parameterOf($endpoint, 'version');

        $certificate = $this->requireCertificate($client);

        $response = $this->materializer->withCertificate(
            $certificate,
            function (string $path) use ($certificate, $endpoint, $body, $schemaService, $version): Response {
                $this->validator->validate(
                    $this->payloadOf($body),
                    self::PAYLOAD_ELEMENT,
                    $schemaService,
                    $version,
                );

                return $this->send($endpoint, $certificate, $path, $body);
            },
        );

        return $this->interpret($response);
    }

    /**
     * Um parâmetro do serviço, ou a recusa que diz qual é.
     *
     * `endpoint()` — em cada conector — já recusa uma fonte que não está na
     * configuração; a chave interna do bloco é um segundo degrau que só apareceu
     * quando o `config` ganhou um segundo serviço: um bloco escrito pela metade
     * (um `xsd_service` esquecido, uma versão que virou `null`) chegava ao
     * validador como aviso de chave indefinida, e o aviso vira `ErrorException`,
     * que não é `RuntimeException` e escapa das guardas da reconciliação. Ler a
     * chave por nome transforma o aviso em recusa nomeada — a mesma forma que o
     * resto do módulo trata como defeito de configuração, e sem nenhum byte na
     * rede.
     *
     * @param  array<string, string>  $endpoint
     */
    private function parameterOf(array $endpoint, string $parameter): string
    {
        $value = $endpoint[$parameter] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Bloco de endpoint sem o parâmetro '{$parameter}'.");
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function send(
        array $endpoint,
        ClientCertificate $certificate,
        string $certificatePath,
        string $body,
    ): Response {
        // Ambiente desconhecido cai em homologação, que é o lado que não
        // produz efeito legal: errar o `FISCAL_ENVIRONMENT` não pode escrever
        // no ambiente de produção.
        $environment = config('fiscal.environment') === 'producao' ? 'producao' : 'homologacao';

        try {
            return Http::withOptions([
                // `verify` apontando para o bundle versionado: a verificação do
                // servidor continua ligada, e a cadeia é a da ICP-Brasil do
                // repositório, não o trust store da máquina.
                'verify' => config('fiscal.ca_bundle'),
                'curl' => [
                    CURLOPT_SSLCERT => $certificatePath,
                    CURLOPT_SSLCERTTYPE => 'P12',
                    // O `libcurl` só recebe caminho para o certificado e a senha
                    // pelo option: sem ela um PKCS#12 cifrado não abre, e a
                    // autenticação é justamente esse certificado. O
                    // materializador já recusou o caso de senha ausente antes
                    // de chegar aqui, então nunca é string vazia.
                    CURLOPT_SSLCERTPASSWD => $certificate->certificatePassword() ?? '',
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                ],
            ])
                ->timeout((int) config('fiscal.timeout', 60))
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                // A ação SOAP viaja no `Content-Type`, e é o `withBody` que
                // escreve esse cabeçalho: montá-lo em `withHeaders` antes
                // seria apagado na linha seguinte.
                ->withBody($body, $this->contentTypeOf($endpoint))
                ->post($this->parameterOf($endpoint, $environment));
        } catch (ConnectionException) {
            // Sem resposta não há status HTTP para classificar, e `0` é a forma
            // que a taxonomia reserva para "não houve resposta": `Upstream`,
            // que adianta repetir. A exceção do transporte é substituída pela
            // nossa porque a mensagem dela carrega o caminho do PFX efêmero.
            throw new FiscalException(
                'O serviço de distribuição está inacessível.',
                FiscalFailure::classify(0, ''),
            );
        }
    }

    /**
     * O corpo que a taxonomia classifica. Uma resposta que o serviço não
     * escreveu no contrato dele — página de erro do proxy, `502` do
     * balanceador — não tem `cStat` para classificar, e quem decide é o status
     * HTTP que ela realmente teve.
     *
     * O fault é conferido **antes** do parser, e não depois: ele vem no mesmo
     * envelope e no mesmo `2xx`, então o parser o leria como "não contém
     * `retDistDFeInt`" — a exceção de parse de uma condição que o serviço
     * esperava que aparecesse, e que nada no resultado permitiria distinguir de
     * um defeito nosso. O caso do parser continua significando o que
     * significava: um `2xx` cujo corpo não é a resposta do serviço.
     */
    private function interpret(Response $response): DfeResponse
    {
        if (! $response->successful()) {
            throw new FiscalException(
                'O serviço de distribuição respondeu fora do contrato do serviço.',
                FiscalFailure::classify($response->status(), ''),
            );
        }

        $body = $response->body();
        $fault = $this->faultOf($body);

        if ($fault !== null) {
            // `classify()` lê status HTTP e `cStat`, e um fault de `2xx` não
            // tem nenhum dos dois. `Upstream` é a escolha porque o fisco está
            // recusando de processar, não recusando o pedido: retentável é o que
            // "tente mais tarde" significa aqui. Isso não vira laço porque quem
            // aciona a captura tem os guardas — uma tentativa por execução, o
            // limite horário de consultas e a janela de bloqueio.
            throw new FiscalException(
                'O serviço de distribuição recusou a chamada: '.($fault === '' ? 'fault sem descrição.' : $fault),
                FiscalFailure::Upstream,
            );
        }

        return $this->parser->parse($body);
    }

    /**
     * O texto do fault, ou `null` quando a resposta não é um fault — a string
     * vazia é um fault que não descreve a condição.
     *
     * O `faultstring` (SOAP 1.1, o que um endpoint `.asmx` devolve) e o
     * `Reason/Text` (SOAP 1.2) são as duas formas do mesmo texto. O `detail`
     * fica de fora: é onde um serviço ecoa o pedido, e o corpo da requisição é
     * uma das coisas que o módulo não registra.
     *
     * Custa um `loadXML` a mais por resposta, e é deliberado: o `DOMDocument`
     * é local deste método e some com ele, então o pico de memória não muda, e
     * o caminho já estava esperando uma chamada de rede.
     */
    private function faultOf(string $body): ?string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);

        // O fault é filho do corpo SOAP, e é por nome local porque o prefixo
        // varia entre o que o serviço declara e o que a gente esperaria.
        $fault = XmlQuery::first($xpath, 'Body/Fault');

        if ($fault === null) {
            return null;
        }

        $text = XmlQuery::first($xpath, 'faultstring', $fault)
            ?? XmlQuery::first($xpath, 'Reason/Text', $fault);

        return $text === null ? '' : $this->condense($text->textContent);
    }

    /**
     * Texto do fisco, do mesmo jeito que `FailedEntry::reason` é uma frase
     * fixa: cabe numa linha de log. O serviço escreve sobre o pedido que
     * recebeu, e o pedido não tem nada que este módulo não registre em outro
     * lugar — mas o tamanho da resposta não pode ser o tamanho do registro, e um
     * `faultstring` longo é o caso comum de serviço em manutenção.
     */
    private function condense(string $text): string
    {
        $condensed = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($condensed) > self::FAULT_TEXT_LIMIT
            ? mb_substr($condensed, 0, self::FAULT_TEXT_LIMIT).'…'
            : $condensed;
    }

    /**
     * O XSD valida o payload, não o envelope SOAP que o embrulha, e o payload
     * sai daqui pelo mesmo caminho de nome local que os dois parsers do módulo
     * usam — a posição da substring não é um contrato.
     */
    private function payloadOf(string $body): string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('O envelope montado não é um XML legível.');
        }

        $payload = XmlQuery::first(new DOMXPath($dom), self::PAYLOAD_ELEMENT);

        if ($payload === null) {
            throw new RuntimeException('Envelope sem payload distDFeInt.');
        }

        // `saveXML` com um nó deste documento não falha; e se falhasse, o
        // validador rejeita o que vier logo abaixo com erro nomeado.
        return $dom->saveXML($payload);
    }

    /**
     * Sem certificado não há mTLS, e mTLS é a autenticação: o serviço nunca
     * chega a ver a consulta. A recusa é um erro comum, e não uma
     * `FiscalException`, porque a taxonomia descreve o que o *serviço*
     * respondeu e aqui ninguém perguntou nada. Quem decide se o cliente é
     * capturável é a captura, antes de chamar o conector.
     *
     * É `FiscalRequestNotSent` e não `RuntimeException` porque essa diferença
     * é o que impede a reconciliação de cobrar uma tentativa de uma consulta que
     * não chegou a existir.
     *
     * É pública porque o conector precisa do mesmo veredito **antes** de gastar
     * o teto horário de consultas e de montar envelope: a reserva de uma
     * consulta pontual só é gasta por consulta que saiu, e uma consulta sem
     * certificado não saiu.
     */
    public function requireCertificate(Client $client): ClientCertificate
    {
        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            throw new FiscalRequestNotSent('Cliente sem certificado A1 vigente.');
        }

        return $certificate;
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function contentTypeOf(array $endpoint): string
    {
        $action = $this->parameterOf($endpoint, 'soap_action');

        return 'application/soap+xml; charset=utf-8; action="'.$action.'"';
    }
}
