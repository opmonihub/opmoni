<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalFailure;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use App\Services\Fiscal\Support\DfeEndpoint;
use App\Services\Fiscal\Support\FiscalEnvironment;
use App\Services\Fiscal\Support\XmlQuery;
use App\Support\HttpPkcs12ClientOptions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * O transporte do `nfeRecepcaoEvento`: a mesma disciplina do `DfeTransport` —
 * certificado do cliente no mTLS, bundle ICP-Brasil verificando o servidor,
 * piso de TLS 1.2, fault condensado — sobre um corpo diferente. O corpo da
 * distribuição é um `distDFeInt` **não assinado**; o daqui é um `envEvento`
 * assinado, e é por isso que esta classe existe em vez de esticar aquela.
 *
 * O que é comum às duas foi copiado de propósito, e não fatorado: a regra do
 * fault e do segredo é a mesma frase, e um terceiro serviço que mudar uma
 * delas não pode herdar a outra por acidente.
 *
 * O que não é comum e esta classe sustenta:
 *
 * 1. **O corpo viaja assinado.** O `envEvento` chega aqui com o `Signature`
 *    do `EventSigner`, e o XSD do serviço de eventos o exige — é a condição
 *    contrária à da distribuição, que recusa assinatura.
 * 2. **A resposta é `retEnvEvento`, não `retDistDFeInt`**, e o veredito fica
 *    no `retEvento` interno — leitura do `ManifestationResult`, acima desta
 *    classe.
 * 3. **Senha e envelope não entram em mensagem nenhuma.** A `ConnectionException`
 *    do Guzzle pode carregar o caminho do PFX ou eco do pedido; ela é
 *    substituída antes de subir, como na distribuição.
 */
final class ManifestationEventTransport
{
    private const CONNECT_TIMEOUT_SECONDS = 15;

    private const FAULT_TEXT_LIMIT = 300;

    public function __construct(
        private ClientCertificateMaterializer $materializer,
        private EventEnvelopeValidator $validator,
    ) {}

    /**
     * Envia o lote `envEvento` já assinado e devolve o `retEnvEvento` em
     * `<cStat, xMotivo, retEvento cStat, retEvento xMotivo>` para o
     * classificador ler.
     *
     * @param  array<string, string>  $endpoint
     * @param  callable|null  $sentAtMarker  chamado uma única vez logo após a
     *                                       requisição ir para a rede — quem
     *                                       enfileirou usa para marcar a
     *                                       tentativa que saiu, com veredito
     *                                       ou sem
     * @return array{lote_cstat: string, lote_xmotivo: string, evento_cstat: ?string, evento_xmotivo: ?string}
     *
     * @throws FiscalRequestNotSent sem certificado utilizável, ou quando o lote
     *                              não passa no XSD local — nada saiu, nada a cobrar
     * @throws FiscalException quando o serviço respondeu fora do contrato ou
     *                         recusou a chamada
     */
    public function send(Client $client, array $endpoint, string $envEventoXml, ?callable $sentAtMarker = null): array
    {
        // As chaves do bloco são conferidas de novo aqui, antes de qualquer
        // byte — a mesma última linha que o transporte de distribuição segura.
        // A seleção de ambiente é fail-closed: um `fiscal.environment` fora da
        // lista lança `FiscalRequestNotSent` em vez de assinar contra a
        // homologação por engano.
        $environment = FiscalEnvironment::selecionar();
        $url = DfeEndpoint::value($endpoint, $environment);

        $certificate = $this->requireCertificate($client);

        $response = $this->materializer->withCertificate(
            $certificate,
            function (string $path) use ($certificate, $endpoint, $envEventoXml, $url, $sentAtMarker): Response {
                // O lote é conferido contra o XSD do serviço de eventos dentro
                // do escopo do material — depois da validação nada sensível fica
                // pendurado na stack.
                $this->validator->validate($envEventoXml);

                return $this->request($certificate, $path, $endpoint, $url, $envEventoXml, $sentAtMarker);
            },
        );

        return $this->interpret($response);
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function request(
        ClientCertificate $certificate,
        string $certificatePath,
        array $endpoint,
        string $url,
        string $envEventoXml,
        ?callable $sentAtMarker,
    ): Response {
        try {
            $pkcs12 = HttpPkcs12ClientOptions::forPath($certificatePath, $certificate->certificatePassword());

            // O serviço de eventos difere do de distribuição num ponto
            // estrutural: o `Body` carrega o `nfeDadosMsg` diretamente, sem o
            // elemento-método (`<nfeRecepcaoEventoNF>`) por cima. Envelopar o
            // holder no método — o que a distribuição faz — derruba o ASMX de
            // eventos num `Object reference` no servidor.
            $inner = '<'.DfeEndpoint::value($endpoint, 'holder')
                .' xmlns="'.DfeEndpoint::value($endpoint, 'namespace').'">'
                .$envEventoXml
                .'</'.DfeEndpoint::value($endpoint, 'holder').'>';

            $body = '<?xml version="1.0" encoding="UTF-8"?>'
                .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
                .'<soap:Body>'.$inner.'</soap:Body>'
                .'</soap:Envelope>';

            $response = Http::withOptions([
                // O servidor de eventos é Let's Encrypt, não ICP-Brasil: o
                // bundle é o de eventos, que cobre as duas cadeias — ver
                // `fiscal.eventos_ca_bundle`. A autenticação mTLS é o A1 do
                // cliente (`$pkcs12`), independente da cadeia do servidor.
                'verify' => config('fiscal.eventos_ca_bundle'),
                ...$pkcs12,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ])
                ->timeout((int) config('fiscal.timeout', 60))
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->withBody(
                    $body,
                    'application/soap+xml; charset=utf-8; action="'.DfeEndpoint::value($endpoint, 'soap_action').'"',
                )
                ->post($url);

            // A tentativa saiu para a rede a partir daqui — com veredito ou
            // com recusa, quem contabiliza retries precisa do `sent_at`. Um
            // marker que falha não pode derrubar a resposta que já voltou.
            if ($sentAtMarker !== null) {
                try {
                    $sentAtMarker();
                } catch (Throwable) {
                    // A marca é contábil; a resposta do fisco vale mais.
                }
            }

            return $response;
        } catch (ConnectionException) {
            // A exceção do Guzzle pode ecoar o caminho do PFX ou pedaços do
            // pedido; a mensagem que sobe é só a nossa.
            throw new FiscalException(
                'O serviço de eventos está inacessível.',
                FiscalFailure::classify(0, ''),
            );
        }
    }

    /**
     * @return array{lote_cstat: string, lote_xmotivo: string, evento_cstat: ?string, evento_xmotivo: ?string}
     */
    private function interpret(Response $response): array
    {
        if (! $response->successful()) {
            throw new FiscalException(
                'O serviço de eventos respondeu fora do contrato do serviço.',
                FiscalFailure::classify($response->status(), ''),
            );
        }

        $body = $response->body();
        $fault = $this->faultOf($body);

        if ($fault !== null) {
            throw new FiscalException(
                'O serviço de eventos recusou a chamada: '.($fault === '' ? 'fault sem descrição.' : $fault),
                FiscalFailure::Upstream,
            );
        }

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new FiscalException(
                'A resposta do serviço de eventos não é um XML legível.',
                FiscalFailure::Upstream,
            );
        }

        $xpath = new DOMXPath($dom);
        $bodyElement = XmlQuery::first($xpath, 'Body');

        // O veredito só é veredito dentro do `Body`: um `retEnvEvento`
        // solto num `detail` de fault, num header ou num anexo casa a busca
        // `//retEnvEvento` inteira — e o primeiro em ordem de documento
        // passaria a ser "a resposta".
        $retEnvEvento = $bodyElement === null
            ? null
            : XmlQuery::first($xpath, 'retEnvEvento', $bodyElement);

        if ($retEnvEvento === null) {
            throw new FiscalException(
                'A resposta do serviço de eventos não contém retEnvEvento.',
                FiscalFailure::Upstream,
            );
        }

        // O `cStat`/`xMotivo` do lote são filhos diretos do `retEnvEvento`, e
        // a busca desce um nível só: um `//cStat` a partir daqui capturaria o
        // do `retEvento` quando o lote não tem o seu — que é exatamente o caso
        // que ainda não tem veredito de evento.
        $retEvento = XmlQuery::first($xpath, 'retEvento/infEvento', $retEnvEvento);

        return [
            'lote_cstat' => $this->directChildText($xpath, $retEnvEvento, 'cStat') ?? '',
            'lote_xmotivo' => $this->directChildText($xpath, $retEnvEvento, 'xMotivo') ?? '',
            'evento_cstat' => $retEvento === null ? null : $this->directChildText($xpath, $retEvento, 'cStat'),
            'evento_xmotivo' => $retEvento === null ? null : $this->directChildText($xpath, $retEvento, 'xMotivo'),
        ];
    }

    /**
     * O texto de um filho direto pelo nome local — `XmlQuery` desce em
     * qualquer nível (`//`), e aqui "o `cStat` do lote" não pode ser o do
     * evento.
     */
    private function directChildText(DOMXPath $xpath, DOMElement $scope, string $localName): ?string
    {
        $found = $xpath->query('*[local-name()="'.$localName.'"]', $scope);

        if ($found === false || $found->length === 0) {
            return null;
        }

        $node = $found->item(0);

        return $node instanceof DOMElement ? $node->textContent : null;
    }

    /**
     * Sem certificado não há mTLS, e mTLS é a autenticação — a mesma frase e a
     * mesma exceção do transporte de distribuição, porque é a mesma recusa.
     */
    private function requireCertificate(Client $client): ClientCertificate
    {
        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            throw new FiscalRequestNotSent('Cliente sem certificado A1 vigente.');
        }

        return $certificate;
    }

    /**
     * O texto do fault, ou `null` quando a resposta não é um fault — regra
     * idêntica à do `DfeTransport`: só o `faultstring`/`Reason/Text` condensado
     * vale mensagem; o `detail`, que é onde o serviço ecoa o pedido, fica de
     * fora.
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
        $fault = XmlQuery::first($xpath, 'Body/Fault');

        if ($fault === null) {
            return null;
        }

        $text = XmlQuery::first($xpath, 'faultstring', $fault)
            ?? XmlQuery::first($xpath, 'Reason/Text', $fault);

        return $text === null ? '' : $this->condense($text->textContent);
    }

    private function condense(string $text): string
    {
        $condensed = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($condensed) > self::FAULT_TEXT_LIMIT
            ? mb_substr($condensed, 0, self::FAULT_TEXT_LIMIT).'…'
            : $condensed;
    }
}
