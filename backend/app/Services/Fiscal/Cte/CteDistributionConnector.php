<?php

namespace App\Services\Fiscal\Cte;

use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Support\ClientStateCode;
use App\Services\Fiscal\Support\DfeEndpoint;
use App\Services\Fiscal\Support\DfePullReader;
use App\Services\Fiscal\Support\DfeResponse;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\DfeTransport;
use RuntimeException;

/**
 * Conector do serviço de Distribution DF-e do CT-e.
 *
 * ⚠️ **Os parâmetros deste serviço não foram verificados deste checkout.** URL de
 * produção, URL de homologação, ação SOAP, método, namespace do payload e a
 * versão `1.00` vêm de `config/fiscal.php`, e foram transcritos de um exemplo de
 * terceiro testado em produção. Nenhuma requisição saiu daqui para o serviço de
 * CT-e. O cabeçalho do bloco de configuração diz o mesmo, e a soma dos dois é o
 * que este arquivo declara: o conector é a fiação do serviço, e a fiação está
 * escrita; o que o fisco responde, ninguém viu ainda.
 *
 * Fala com um serviço só e devolve documento pronto, como o conector de NF-e. O
 * que é comum aos dois — a chamada SOAP com o certificado do cliente, a leitura
 * da resposta, a conversão das entradas em documentos e a leitura da rejeição —
 * mora em `DfeTransport`, em `DfeEntryCollector` (dentro do leitor) e em
 * `DfePullReader`. Aqui fica o que é do CT-e: a fonte, a família que a consulta
 * pergunta, a consulta por posição e a recusa da consulta por chave.
 *
 * Não escreve no banco: `FiscalDocumentWriter` é o único caminho de escrita, e
 * é por isso que painel e tabela são escritos uma vez só.
 *
 * Quatro regras que este arquivo existe para sustentar:
 *
 * 1. **A requisição não é assinada.** O schema local recusa assinatura injetada
 *    antes de qualquer byte — `distDFeInt_v1.00.xsd` é um `xs:sequence` fechado,
 *    sem `xs:any` —, e a autenticação é o certificado A1 do cliente no
 *    transporte. Não existe XMLDSig aqui nem em qualquer outro arquivo do módulo.
 *    ⚠️ A outra metade da frase, o `cStat 215` do serviço, é fato verificado do
 *    **NF-e** e trazido para cá por analogia: este repositório não chamou o
 *    serviço de CT-e nenhuma vez, e o que responde `215` a um corpo assinado é
 *    uma das coisas que o canário de um cliente vai conferir.
 * 2. **A posição nunca é incrementada.** `ultNSU` no pedido é a posição que o
 *    consumidor já tem, e `lastNsu` no resultado é o valor que a resposta
 *    devolveu — nunca o local somado de um.
 * 3. **Nada é manifestado.** Este conector consulta e lê. O `210200` é um ato
 *    legal que bloquearia o cancelamento do emissor, e nenhum caminho de código
 *    deste módulo o envia.
 * 4. **A consulta por chave de acesso não existe.** O serviço de CT-e é limitado
 *    a consulta por posição, e a recusa é antes de qualquer requisição — não uma
 *    resposta do fisco transformada em exceção depois de uma ida à rede.
 */
final class CteDistributionConnector implements FiscalConnector
{
    /**
     * A família que este conector traz. Não é o modelo do documento: o serviço
     * de CT-e entrega CT-e regular e simplificado (`57`), CT-e OS (`67`) e
     * GTV-e (`64`) no mesmo lote, e é a chave de acesso de cada documento que
     * diz qual é. O coletor compara cada documento com a família, e um `resNFe`
     * entregue aqui é recusado — é um documento real com etiqueta errada.
     */
    private const MODEL = FiscalModel::Cte;

    public function __construct(
        private DfeSoapEnvelope $envelope,
        private DfeTransport $transport,
        private DfePullReader $reader,
        private ClientStateCode $stateCode,
        private FiscalLookupBudget $lookupBudget,
    ) {}

    public function source(): FiscalSource
    {
        return FiscalSource::CteDistribuicao;
    }

    /**
     * `$limit` é informativo: o serviço não aceita parametrizar o tamanho do
     * lote, e quem chama já leu `config('fiscal.batch_limit')`.
     */
    public function pull(Client $client, int $fromNsu, int $limit): PullResult
    {
        // O veredito do certificado vem antes de qualquer montagem: um cliente
        // sem A1 não produz envelope, não passa pelo XSD e não gasta banda.
        $this->transport->requireCertificate($client);

        return $this->reader->read($this->send($client, $fromNsu), self::MODEL);
    }

    /**
     * O serviço de distribuição de CT-e **não oferece consulta por chave de
     * acesso**: a porta dele é a posição, `consNSU`. Por isso a recusa sai aqui,
     * sem montagem de corpo e sem qualquer byte na rede — uma
     * `FiscalException` depois de uma ida ao fisco seria a resposta de um serviço
     * que ninguém perguntou, com a assinatura de uma falha de transporte.
     *
     * A assinatura do contrato exige o método, e o contrato não tem como dizer
     * "este serviço não tem esta porta": a recusa é a implementação honesta de
     * uma porta que não existe. E o XSD local do CT-e também não declara
     * `consChCTe` — a segunda vez que essa recusa está escrita, em um lugar que
     * ninguém burla com um corpo montado à mão.
     */
    public function fetchByChave(Client $client, string $chave): ?PulledDocument
    {
        throw new RuntimeException(
            'O serviço de distribuição de CT-e não oferece consulta por chave de acesso; a porta dele é a posição (consNSU).',
        );
    }

    /**
     * A consulta por posição é a porta de entrada para fechar buraco, e a única
     * que o serviço de CT-e oferece. É a consulta cara — o fisco conta consulta
     * pontual no mesmo teto por CNPJ para os dois serviços —, e por isso ela
     * reserva a vaga antes de qualquer byte na rede.
     */
    public function fetchByNsu(Client $client, int $nsu): ?PulledDocument
    {
        // O veredito do certificado vem antes da reserva: uma consulta que não
        // chegou a existir não pode ser cobrada como consulta que saiu, e é a
        // vaga do teto que se cobra aqui.
        $this->transport->requireCertificate($client);

        $this->reserveLookup($client);

        return $this->reader->readOne($this->sendByNsu($client, $nsu), self::MODEL);
    }

    /**
     * Teto estourado não é erro do fisco nem do transporte: é uma decisão nossa
     * de não consultar agora, e a exceção é nomeada para que a reconciliação
     * adie a posição sem gastar a tentativa de uma consulta que nunca saiu.
     */
    private function reserveLookup(Client $client): void
    {
        if (! $this->lookupBudget->reserve($client)) {
            throw new FiscalLookupDeferred('Limite horário de consultas pontuais atingido.');
        }
    }

    private function send(Client $client, int $fromNsu): DfeResponse
    {
        $endpoint = $this->endpoint();

        return $this->transport->request($client, $endpoint, $this->envelopeFor($endpoint, $client, $fromNsu));
    }

    private function sendByNsu(Client $client, int $nsu): DfeResponse
    {
        $endpoint = $this->endpoint();

        // O corpo nasce do `build()` com a posição zero — é o grupo de posição
        // que a conversão troca, e a conversão recusa um corpo que não tem
        // exatamente um grupo para trocar. Mesmo validador do caminho
        // incremental: o `consNSU` é uma das opções do grupo de consulta do
        // `distDFeInt` do CT-e, então a checagem cobre a posição pedida com
        // quinze dígitos, a versão `1.00`, a ordem dos elementos e o CNPJ e a UF
        // do próprio pedido.
        return $this->transport->request(
            $client,
            $endpoint,
            $this->envelope->pointNsu($this->envelopeFor($endpoint, $client, 0), $nsu),
        );
    }

    /**
     * As diferenças entre os dois serviços do módulo — namespace, versão,
     * método e o elemento que embrulha o payload — vêm da configuração, então
     * nenhum conector precisa editar o envelope. A UF do interessado também é
     * comum: `cUFAutor` é a UF de quem pergunta, e a tabela é a mesma nos dois
     * serviços (`ClientStateCode`), porque a UF não muda com o serviço que a
     * recebe.
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
            cUf: $this->stateCode->of($client),
            fromNsu: $fromNsu,
            method: $endpoint['method'],
            holder: $endpoint['holder'],
        );
    }

    /**
     * O bloco de `fiscal.endpoints` deste serviço, conferido inteiro.
     *
     * A conferência é do bloco, e não de cada leitura: `envelopeFor()` roda
     * **antes** de `DfeTransport::request()` — ele é avaliado como argumento —,
     * então uma guarda dentro de `request()` nunca alcançaria `version`, que é
     * lida aqui. `DfeEndpoint` confere as nove chaves de uma vez, e o que chega
     * nas leituras seguintes já pode ser lido.
     *
     * @return array<string, string>
     */
    private function endpoint(): array
    {
        return DfeEndpoint::of((array) config('fiscal.endpoints', []), $this->source()->value);
    }
}
