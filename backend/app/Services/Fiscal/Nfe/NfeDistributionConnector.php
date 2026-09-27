<?php

namespace App\Services\Fiscal\Nfe;

use App\Enums\FiscalFailure;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Contracts\FailedEntry;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use App\Services\Fiscal\Support\DfeResponse;
use App\Services\Fiscal\Support\DfeResponseParser;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\DocZipDecoder;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use App\Services\Fiscal\Support\XmlQuery;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Conector do serviço de Distribution DF-e da NF-e.
 *
 * Fala com um serviço só, faz o parse do envelope dele e devolve documento
 * pronto. Não escreve no banco: `FiscalDocumentWriter` é o único caminho de
 * escrita, e é por isso que painel e tabela são escritos uma vez só.
 *
 * Três regras que este arquivo existe para sustentar:
 *
 * 1. **A requisição não é assinada.** O serviço não assina, o XSD rejeita
 *    assinatura injetada com `cStat 215`, e a autenticação é o certificado A1
 *    do cliente no transporte. Não existe XMLDSig aqui nem em qualquer outro
 *    arquivo do módulo.
 * 2. **A posição nunca é incrementada.** `ultNSU` no pedido é a posição que o
 *    consumidor já tem, e `lastNsu` no resultado é o valor que a resposta
 *    devolveu — nunca o local somado de um.
 * 3. **Nada é manifestado.** Este conector consulta e lê. O `210200` é um
 *    ato legal que bloquearia o cancelamento do emissor, e nenhum caminho de
 *    código deste módulo o envia.
 */
final class NfeDistributionConnector implements FiscalConnector
{
    /**
     * Códigos IBGE das UFs, de `config('fiscal.environment')` para longe:
     * `cUFAutor` é a UF do interessado — o cliente — e não a do serviço.
     *
     * Sigla fora desta tabela não vira São Paulo. O serviço aceita qualquer
     * código válido da tabela e `35` é um deles, então um valor inventado
     * passaria pela validação do XSD e seria aceito: uma afirmação falsa
     * sobre quem pergunta, sem nada para denunciá-la.
     */
    private const UF_CODES = [
        'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29, 'CE' => 23,
        'DF' => 53, 'ES' => 32, 'GO' => 52, 'MA' => 21, 'MT' => 51, 'MS' => 50,
        'MG' => 31, 'PA' => 15, 'PB' => 25, 'PR' => 41, 'PE' => 26, 'PI' => 22,
        'RJ' => 33, 'RN' => 24, 'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42,
        'SP' => 35, 'SE' => 28, 'TO' => 17,
    ];

    /**
     * Tempo de conexão é o do transporte, não o do fisco: um lote de 50
     * documentos leva de 1 a 3 MB com mTLS, e a janela do worker é de 120
     * segundos. Esperar mais que isso por um TCP handshake só rouba o tempo do
     * resto do lote.
     */
    private const CONNECT_TIMEOUT_SECONDS = 15;

    public function __construct(
        private DfeSoapEnvelope $envelope,
        private DfeResponseParser $parser,
        private DocZipDecoder $decoder,
        private FiscalXmlMetadata $metadata,
        private FiscalXmlValidator $validator,
        private ClientCertificateMaterializer $materializer,
    ) {}

    public function source(): FiscalSource
    {
        return FiscalSource::NfeDistribuicao;
    }

    /**
     * `$limit` é informativo: o serviço não aceita parametrizar o tamanho do
     * lote, e quem chama já leu `config('fiscal.batch_limit')`.
     */
    public function pull(Client $client, int $fromNsu, int $limit): PullResult
    {
        $certificate = $this->certificateOf($client);

        $response = $this->materializer->withCertificate(
            $certificate,
            fn (string $path): Response => $this->send($certificate, $path, $client, $fromNsu),
        );

        $parsed = $this->interpret($response);

        // O status HTTP real entra na classificação: só um `2xx` faria o
        // default de `classify()` recair em `Rejected` para o que é
        // indisponibilidade do serviço.
        $failure = FiscalFailure::classify($response->status(), $parsed->cStat);

        if ($failure === FiscalFailure::DocumentsFound) {
            return $this->collect($parsed);
        }

        // `137` e a rejeição de consumo indevido são a mesma regra — parar uma
        // hora — e o eixo mora no enum, não neste `match`. Uma indisponibilidade
        // do serviço não entra aqui: ela adianta repetir, e um retry não pode
        // virar uma hora de silêncio por cliente.
        if ($failure->blocksForAnHour()) {
            return new PullResult(
                documents: [],
                lastNsu: $parsed->ultNsu,
                maxNsu: $parsed->maxNsu,
                more: false,
                blockedUntil: $this->blockUntil(),
                // "Nenhum documento localizado" e "consumo indevido" bloqueiam
                // igual e discordam sobre a posição. A primeira não entrega
                // nada, e o que devolve é o eco da posição pedida: a posição
                // armazenada fica intacta. A segunda entrega a posição correta
                // dentro do próprio corpo da rejeição, e é a única alavanca de
                // recuperação que o serviço oferece — descartá-la custaria
                // recomeçar do começo.
                mayAdoptPosition: $failure !== FiscalFailure::NoDocuments,
            );
        }

        throw new FiscalException(
            $parsed->xMotivo === '' ? 'O serviço de distribuição rejeitou a consulta.' : $parsed->xMotivo,
            $failure,
        );
    }

    /**
     * `null` significa uma coisa só: o serviço respondeu que aquela chave não
     * está com ele. Bloqueio, indisponibilidade e rejeição viram
     * `FiscalException`, porque devolver `null` nos três casos diria "esta
     * chave não existe" e mandaria quem reconcilia procurar a próxima — com o
     * CNPJ já bloqueado e o limite horário de consultas sendo gasto.
     */
    public function fetchByChave(Client $client, string $chave): ?PulledDocument
    {
        if (! FiscalXmlMetadata::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso inválida: {$chave}.");
        }

        $certificate = $this->certificateOf($client);

        $response = $this->materializer->withCertificate(
            $certificate,
            fn (string $path): Response => $this->sendByChave($certificate, $path, $client, $chave),
        );

        $parsed = $this->interpret($response);
        $failure = FiscalFailure::classify($response->status(), $parsed->cStat);

        if ($failure === FiscalFailure::NoDocuments) {
            return null;
        }

        if ($failure !== FiscalFailure::DocumentsFound) {
            throw new FiscalException(
                $parsed->xMotivo === '' ? 'O serviço de distribuição rejeitou a consulta.' : $parsed->xMotivo,
                $failure,
            );
        }

        $result = $this->collect($parsed);

        if ($result->documents !== []) {
            return $result->documents[0];
        }

        // Uma resposta de "localizado" que não virou documento é conteúdo que
        // não deu para ler, e isso não é a mesma coisa que o serviço não ter a
        // chave. Devolver `null` aqui diria que a posição está vazia, e quem
        // reconcilia contaria a consulta como feita e seguiria para a próxima,
        // com o buraco intacto e o limite horário de consultas gasto. A
        // assinatura do contrato não tem onde carregar a lista de recusas, então
        // a falha sobe — alta e nomeada, como todo o resto do módulo.
        $refused = $result->failures[0];

        throw new RuntimeException("A resposta do serviço traz uma entrada que não pôde ser lida na posição {$refused->nsu}: {$refused->reason}");
    }

    /**
     * Lê o lote entrada a entrada, e uma entrada que não vira documento não
     * interrompe as outras: o serviço entrega posições, e uma posição ilegível
     * é um buraco a reconciliar, não o fim da fila. O que decide o cursor é
     * `mayAdoptPosition`, e é por isso que a recusa de uma entrada tem de
     * aparecer no resultado em vez de sumir.
     *
     * Cada `try` envolve uma única chamada, então o `RuntimeException` capturado
     * só pode ter vindo dela — `DocZipDecoder` e `FiscalXmlMetadata` lançam
     * `RuntimeException` e não existe tipo mais estreito para pegar.
     */
    private function collect(DfeResponse $parsed): PullResult
    {
        $documents = [];
        $failures = [];

        foreach ($parsed->entries as $entry) {
            try {
                $xml = $this->decoder->decode($entry->payload);
            } catch (RuntimeException) {
                $failures[] = new FailedEntry(
                    nsu: $entry->nsu,
                    schema: $entry->schema,
                    reason: 'DocZipDecoder não decodificou o payload comprimido.',
                );

                continue;
            }

            try {
                $extracted = $this->metadata->extract($xml, FiscalModel::Nfe);
            } catch (RuntimeException) {
                // A chave com dígito verificador inválido, o modelo que não é o
                // do serviço e o XML ilegível chegam todos aqui, e em nenhum
                // deles houve o suficiente para guardar o documento.
                $failures[] = new FailedEntry(
                    nsu: $entry->nsu,
                    schema: $entry->schema,
                    reason: 'FiscalXmlMetadata rejeitou o documento decodificado.',
                );

                continue;
            }

            $documents[] = new PulledDocument(
                model: $extracted->model,
                kind: $extracted->kind,
                chave: $extracted->chave,
                eventId: $extracted->eventId,
                emitenteCnpj: $extracted->emitenteCnpj,
                destinatarioCnpj: $extracted->destinatarioCnpj,
                valorTotal: $extracted->valorTotal,
                nsu: $entry->nsu,
                schema: $entry->schema,
                emissaoAt: $extracted->emissaoAt,
                eventoOcorridoEmAt: $extracted->eventoOcorridoEmAt,
                xml: $xml,
            );
        }

        return new PullResult(
            documents: $documents,
            lastNsu: $parsed->ultNsu,
            maxNsu: $parsed->maxNsu,
            more: $parsed->maxNsu !== null && $parsed->ultNsu < $parsed->maxNsu,
            blockedUntil: null,
            // Havendo buraco, a posição não é adotada: a próxima consulta volta
            // a pedir a partir da posição anterior e tenta ler a entrada de novo.
            mayAdoptPosition: $failures === [],
            failures: $failures,
        );
    }

    private function send(
        ClientCertificate $certificate,
        string $certificatePath,
        Client $client,
        int $fromNsu,
    ): Response {
        $endpoint = $this->endpoint();

        $body = $this->envelopeFor($endpoint, $client, $fromNsu);

        $this->validator->validate($this->payloadOf($body), 'distDFeInt');

        return $this->request($endpoint, $certificate, $certificatePath, $body);
    }

    private function sendByChave(
        ClientCertificate $certificate,
        string $certificatePath,
        Client $client,
        string $chave,
    ): Response {
        $endpoint = $this->endpoint();

        $body = $this->lookupOf($this->envelopeFor($endpoint, $client, 0), $chave);

        // O corpo da consulta por chave é o único que sai de uma reescrita de
        // outro corpo, e é por isso que ele também passa pelo schema local: a
        // reescrita é exatamente o que o validador existe para pegar. O XSD
        // aceita `consChNFe` — é uma das três opções do grupo de consulta do
        // `distDFeInt` — então a checagem cobre a posição do `consChNFe`, a
        // versão, a ordem dos elementos e o CNPJ e a UF do próprio pedido, e
        // não só a chave, que `isValidChave()` já conferiu antes de chegar aqui.
        $this->validator->validate($this->payloadOf($body), 'distDFeInt');

        return $this->request($endpoint, $certificate, $certificatePath, $body);
    }

    /**
     * Todas as diferenças entre os dois serviços do módulo — namespace, versão,
     * método e o elemento que embrulha o payload — vêm da configuração, então
     * nenhum conector precisa editar o envelope.
     *
     * @param  array<string, string>  $endpoint
     */
    private function envelopeFor(array $endpoint, Client $client, int $fromNsu): string
    {
        return $this->envelope->build(
            serviceNamespace: $endpoint['namespace'],
            payloadNamespace: $endpoint['payload_namespace'],
            version: $endpoint['version'],
            cnpj: (string) $client->tax_id,
            cUf: $this->ufCodeOf($client),
            fromNsu: $fromNsu,
            method: $endpoint['method'],
            holder: $endpoint['holder'],
        );
    }

    /**
     * O `DfeSoapEnvelope` monta um corpo de consulta por posição, e o serviço
     * também aceita consulta por chave: a diferença é o grupo de consulta, que
     * o XSD descreve como escolha entre `distNSU`, `consNSU` e `consChNFe`. Não
     * há construtor para o outro corpo, e a reescrita é conferida porque
     * `str_replace` que não encontra o padrão devolve o corpo intacto em
     * silêncio: um `distNSU` sob o nome de consulta por chave voltaria com o
     * documento da posição zero, que é a resposta errada com aparência de
     * resposta certa.
     */
    private function lookupOf(string $body, string $chave): string
    {
        $position = '<distNSU><ultNSU>'.str_pad('0', 15, '0', STR_PAD_LEFT).'</ultNSU></distNSU>';
        $lookup = '<consChNFe><chNFe>'.$chave.'</chNFe></consChNFe>';

        $substituted = str_replace($position, $lookup, $body);

        if (! str_contains($substituted, $lookup) || str_contains($substituted, 'distNSU')) {
            throw new RuntimeException('O envelope não pôde ser convertido em consulta por chave de acesso.');
        }

        return $substituted;
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function request(
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
                ->post($endpoint[$environment]);
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
     */
    private function interpret(Response $response): DfeResponse
    {
        if (! $response->successful()) {
            throw new FiscalException(
                'O serviço de distribuição respondeu fora do contrato do serviço.',
                FiscalFailure::classify($response->status(), ''),
            );
        }

        return $this->parser->parse($response->body());
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

        $payload = XmlQuery::first(new DOMXPath($dom), 'distDFeInt');

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
     */
    private function certificateOf(Client $client): ClientCertificate
    {
        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            throw new RuntimeException('Cliente sem certificado A1 vigente.');
        }

        return $certificate;
    }

    private function ufCodeOf(Client $client): string
    {
        $acronym = strtoupper(trim((string) $client->state));
        $code = self::UF_CODES[$acronym] ?? null;

        if ($code === null) {
            throw new RuntimeException("UF do cliente {$client->tax_id} não está na tabela de UFs: '{$client->state}'.");
        }

        return (string) $code;
    }

    /**
     * @return array<string, string>
     */
    private function endpoint(): array
    {
        /** @var array<string, array<string, string>> $endpoints */
        $endpoints = (array) config('fiscal.endpoints', []);

        if (! isset($endpoints[$this->source()->value])) {
            throw new RuntimeException('Endpoint de distribuição não configurado.');
        }

        return $endpoints[$this->source()->value];
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function contentTypeOf(array $endpoint): string
    {
        return 'application/soap+xml; charset=utf-8; action="'.$endpoint['soap_action'].'"';
    }

    private function blockUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60));
    }
}
