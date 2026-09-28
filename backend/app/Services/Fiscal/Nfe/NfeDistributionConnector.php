<?php

namespace App\Services\Fiscal\Nfe;

use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Support\ClientStateCode;
use App\Services\Fiscal\Support\DfePullReader;
use App\Services\Fiscal\Support\DfeResponse;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\DfeTransport;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use Closure;
use RuntimeException;

/**
 * Conector do serviço de Distribution DF-e da NF-e.
 *
 * Fala com um serviço só e devolve documento pronto. O que é comum aos serviços
 * de DF-e — a chamada SOAP com o certificado do cliente, a leitura da resposta,
 * a conversão das entradas em documentos, a leitura da rejeição e a tabela de
 * UFs — mora em `DfeTransport`, `DfeEntryCollector`, `DfePullReader` e
 * `ClientStateCode`; aqui fica o que é da NF-e: os parâmetros do serviço e a
 * consulta por chave, que é a porta que o serviço de CT-e não tem.
 *
 * Não escreve no banco: `FiscalDocumentWriter` é o único caminho de
 * escrita, e é por isso que painel e tabela são escritos uma vez só.
 *
 * Quatro regras que este arquivo existe para sustentar:
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
 * 4. **Consulta pontual é a cara cara, e ela tem teto.** As duas consultas de
 *    uma posição só — por chave e por NSU — reservam uma vaga no limite
 *    horário do CNPJ antes de qualquer byte na rede, e `pull` não gasta dessa
 *    cota. O fisco bloqueia quem consome de mais, e o laço de reconciliação é
 *    justamente o que dispararia o bloqueio se ninguém contasse por ele.
 */
final class NfeDistributionConnector implements FiscalConnector
{
    /**
     * O documento que este conector traz: a chave de acesso carrega `55` nos
     * dois dígitos do modelo, e a extração de metadados recusa tudo que não for.
     * A regra é da família do modelo pedido, e não uma conclusão da entrada — é
     * o coletor que compara. A família da NF-e é unitária: NFC-e tem serviço de
     * distribuição próprio.
     */
    private const MODEL = FiscalModel::Nfe;

    public function __construct(
        private DfeSoapEnvelope $envelope,
        private DfeTransport $transport,
        private DfePullReader $reader,
        private ClientStateCode $stateCode,
        private FiscalLookupBudget $lookupBudget,
    ) {}

    public function source(): FiscalSource
    {
        return FiscalSource::NfeDistribuicao;
    }

    /**
     * `$limit` é informativo: o serviço não aceita parametrizar o tamanho do
     * lote, e quem chama já leu `config('fiscal.batch_limit')`.
     *
     * O que a resposta significa — lote, pausa de uma hora ou recusa — é regra do
     * `DfePullReader`, e é a mesma para os dois serviços deste módulo.
     */
    public function pull(Client $client, int $fromNsu, int $limit): PullResult
    {
        // O veredito do certificado vem antes de qualquer montagem: um cliente
        // sem A1 não produz envelope, não passa pelo XSD e não gasta banda.
        $this->transport->requireCertificate($client);

        return $this->reader->read($this->send($client, $fromNsu), self::MODEL);
    }

    /**
     * `null` significa uma coisa só: **o serviço diz que não tem aquele
     * documento.** Qualquer outra coisa é alta.
     *
     * Bloqueio, indisponibilidade e rejeição viram `FiscalException`, porque
     * `null` nesses casos diria "esta chave não existe" e mandaria quem
     * reconcilia procurar a próxima — com o CNPJ bloqueado e o limite horário de
     * consultas sendo gasto.
     *
     * E um "localizado" cujo documento não pôde ser lido também não é `null`: é
     * um documento que chegou e uma entrada que não deu para abrir, que é o
     * contrário de "o serviço não tem a chave". A assinatura do contrato não
     * tem onde carregar a lista de recusas, e um `FiscalFailure` mentiria sobre
     * a origem — a taxonomia classifica o que o *serviço* respondeu, e quem
     * recusou a entrada foi o nosso parse. Então a falha sobe como
     * `RuntimeException` nomeada, com a posição e o mesmo `reason` seguro para
     * log que vai em `FailedEntry`.
     */
    public function fetchByChave(Client $client, string $chave): ?PulledDocument
    {
        if (! FiscalXmlMetadata::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso inválida: {$chave}.");
        }

        return $this->pointLookup($client, fn (): DfeResponse => $this->sendByChave($client, $chave));
    }

    /**
     * A consulta por posição é a outra porta de entrada para fechar buraco, e a
     * que o fisco nomeia para isso: ele reconhece um NSU faltante e devolve o
     * documento daquela posição. A chave de acesso é mais precisa, mas nem
     * sempre é conhecida — o buraco encontrado por quem reconcilia a sequência
     * é uma posição, não uma chave.
     *
     * É a consulta que o teto horário de consultas pontuais existe para
     * segurar, e por isso ela reserva a vaga antes de qualquer byte na rede.
     */
    public function fetchByNsu(Client $client, int $nsu): ?PulledDocument
    {
        return $this->pointLookup($client, fn (): DfeResponse => $this->sendByNsu($client, $nsu));
    }

    /**
     * O que as duas consultas pontuais têm em comum, e que por isso mora em um
     * método só: a reserva do teto **antes** de qualquer chamada e a mesma leitura
     * da resposta.
     *
     * A reserva vem antes da requisição e não depois de um resultado: o fisco
     * conta a consulta que saiu, e a que não saiu por falta de teto é
     * justamente a que ele não pode contar. Adiar é `FiscalLookupDeferred`, e
     * não retentativa nem silêncio: quem chama decide o que fazer com a posição
     * que ficou sem resposta.
     *
     * A leitura — inclusive a regra de `null` e a recusa de entrada ilegível — é
     * do `DfePullReader`, e é a mesma para os dois serviços deste módulo.
     *
     * @param  Closure(): DfeResponse  $ask
     */
    private function pointLookup(Client $client, Closure $ask): ?PulledDocument
    {
        // O veredito do certificado vem antes da reserva: uma consulta que não
        // chegou a existir não pode ser cobrada como consulta que saiu, e é a
        // vaga do teto que se cobra aqui.
        $this->transport->requireCertificate($client);

        $this->reserveLookup($client);

        return $this->reader->readOne($ask(), self::MODEL);
    }

    /**
     * Teto estourado não é erro do fisco nem do transporte: é uma decisão
     * nossa de não consultar agora, e a exceção é nomeada para que a
     * reconciliação adie a posição sem gastar a tentativa de uma consulta que
     * nunca saiu.
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

    private function sendByChave(Client $client, string $chave): DfeResponse
    {
        $endpoint = $this->endpoint();

        // O corpo da consulta por chave sai de uma reescrita de outro corpo, e
        // é por isso que ele também passa pelo schema local: a reescrita é
        // exatamente o que o validador existe para pegar. O XSD aceita
        // `consChNFe` — é uma das três opções do grupo de consulta do
        // `distDFeInt` — então a checagem cobre a posição do `consChNFe`, a
        // versão, a ordem dos elementos e o CNPJ e a UF do próprio pedido, e
        // não só a chave, que `isValidChave()` já conferiu antes de chegar aqui.
        return $this->transport->request(
            $client,
            $endpoint,
            $this->lookupOf($this->envelopeFor($endpoint, $client, 0), $chave),
        );
    }

    private function sendByNsu(Client $client, int $nsu): DfeResponse
    {
        $endpoint = $this->endpoint();

        // O corpo nasce do `build()` com a posição zero — é o grupo de posição
        // que a conversão troca, e a conversão recusa um corpo que não tem
        // exatamente um grupo para trocar. Mesmo validador dos outros dois
        // caminhos: o `consNSU` é uma das opções do grupo de consulta do
        // `distDFeInt`, então a checagem cobre a posição pedida com quinze
        // dígitos, a versão, a ordem dos elementos e o CNPJ e a UF do próprio
        // pedido.
        return $this->transport->request(
            $client,
            $endpoint,
            $this->envelope->pointNsu($this->envelopeFor($endpoint, $client, 0), $nsu),
        );
    }

    /**
     * Todas as diferenças entre os dois serviços do módulo — namespace, versão,
     * método e o elemento que embrulha o payload — vêm da configuração, então
     * nenhum conector precisa editar o envelope. A tabela de UFs também é
     * comum (`ClientStateCode`).
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
}
