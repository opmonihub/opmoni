<?php

namespace App\Services\Fiscal\Nfse;

use App\Enums\FiscalFailure;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use App\Support\HttpPkcs12ClientOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * O transporte REST da ADN contribuintes: um GET com o certificado A1 do
 * cliente no mTLS e nada mais.
 *
 * É o irmão REST de `DfeTransport`, com duas diferenças que valem classe
 * separada (ver `add-nfse-adn-capture/design.md`, decisão 1):
 *
 * 1. **A resposta não é classificada aqui.** O status HTTP que a ADN devolve
 *    engana — `404` com corpo de negócio é "não há documento", não "rede caiu"
 *    —, então o transporte devolve a resposta inteira e quem decide o que ela
 *    significa é `NfseAdnPullReader`. A única exceção é a chamada que não
 *    produziu resposta alguma (`ConnectionException`): sem status e sem corpo,
 *    ela vira `FiscalException` com o `0` da taxonomia.
 * 2. **Não há corpo a validar.** A ADN é GET sem payload, então não existe XSD
 *    local, e a checagem do certificado é a única guarda antes do byte.
 *
 * As regras de `DfeTransport` que aqui valem inteiras: a senha do certificado
 * vive só no escopo da opção do `curl`, e nenhuma resposta — XML bruto, token,
 * payload — é registrada em log. A que não vale é a do trust store: o
 * `DfeTransport` confere os hosts da SEFAZ contra o bundle ICP-Brasil
 * versionado (`fiscal.ca_bundle`), e este transporte confere o front do ADN
 * contra o trust store do sistema, porque o canário observou cadeia Let's
 * Encrypt, não ICP, no `adn.nfse.gov.br`.
 */
final class NfseAdnTransport
{
    /**
     * Ver `DfeTransport::CONNECT_TIMEOUT_SECONDS`: o tempo de conexão é do
     * transporte, e a janela do worker é de 120 segundos.
     */
    private const CONNECT_TIMEOUT_SECONDS = 15;

    public function __construct(
        private ClientCertificateMaterializer $materializer,
    ) {}

    /**
     * Sem certificado não há mTLS, e mTLS é a autenticação: a ADN identifica
     * o contribuinte pelo certificado e nunca chega a ver a consulta de quem
     * não o apresenta. `FiscalRequestNotSent`, e não `FiscalException`, porque
     * a requisição não saiu — e é a diferença que impede quem reconcilia de
     * cobrar uma tentativa de uma consulta que nunca existiu.
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
     * O GET mTLS. O `$url` chega pronto de quem chamou — conector ou comando
     * de probe —, porque a posição vai no caminho e o caminho é o que
     * distingue as três consultas (`/DFe/{nsu}`, `/DFe/{nsu}` de posição
     * fechada e a consulta por chave).
     *
     * O status não é conferido aqui: 2xx, 4xx e 5xx com corpo são matéria do
     * leitor, que classifica o corpo antes de confiar no número.
     */
    public function get(Client $client, string $url): Response
    {
        // O veredito do certificado vem antes de qualquer materialização: um
        // cliente sem A1 não abre conexão nenhuma.
        $certificate = $this->requireCertificate($client);

        try {
            return $this->materializer->withCertificate(
                $certificate,
                fn (string $certificatePath): Response => $this->send($certificate, $certificatePath, $url),
            );
        } catch (ConnectionException) {
            // Sem resposta não há status HTTP para classificar, e `0` é a forma
            // que a taxonomia reserva para "não houve resposta": `Upstream`,
            // que adianta repetir. A exceção do transporte é substituída pela
            // nossa porque a mensagem dela carrega o caminho do PFX efêmero.
            throw new FiscalException(
                'O serviço da ADN está inacessível.',
                FiscalFailure::classify(0, ''),
            );
        }
    }

    private function send(ClientCertificate $certificate, string $certificatePath, string $url): Response
    {
        $pkcs12 = HttpPkcs12ClientOptions::forPath($certificatePath, $certificate->certificatePassword());

        return Http::withOptions([
            // A verificação do servidor usa o trust store do sistema: o canário
            // observou que o front do ADN (`adn.nfse.gov.br`) serve cadeia
            // pública Let's Encrypt (ISRG/YR1), não ICP-Brasil — o bundle
            // versionado de `fiscal.ca_bundle`, que os irmãos SOAP usam para os
            // hosts da SEFAZ, não contém essas raízes e recusa o servidor. O
            // mTLS do cliente continua sendo o A1 ICP-Brasil; quem confere a
            // cadeia de *quem atende* é o trust store da máquina.
            'verify' => true,
            ...$pkcs12,
            // O piso de TLS 1.2: no Guzzle 8 é a opção de requisição
            // `crypto_method` que vira o `CURLOPT_SSLVERSION` — e sem ela o
            // piso já seria 1.2, mas explicitado o intent não depende de
            // default de biblioteca.
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ])
            ->timeout((int) config('fiscal.timeout', 60))
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->get($url);
    }
}
