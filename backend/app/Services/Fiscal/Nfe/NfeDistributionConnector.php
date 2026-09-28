<?php

namespace App\Services\Fiscal\Nfe;

use App\Enums\FiscalFailure;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalClientStateUnknown;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Support\DfeEntryCollector;
use App\Services\Fiscal\Support\DfeResponse;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\DfeTransport;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;

/**
 * Conector do serviço de Distribution DF-e da NF-e.
 *
 * Fala com um serviço só e devolve documento pronto. O que é comum aos serviços
 * de DF-e — a chamada SOAP com o certificado do cliente, a leitura da resposta,
 * a conversão das entradas em documentos — mora em `DfeTransport` e
 * `DfeEntryCollector`; aqui fica o que é da NF-e: os parâmetros do serviço, a
 * UF do interessado, a consulta por chave e a leitura da rejeição.
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
     * O documento que este conector traz: a chave de acesso carrega `55` nos
     * dois dígitos do modelo, e a extração de metadados recusa tudo que não for.
     * A regra é a do par (conector, modelo) e não uma conclusão da entrada — é o
     * coletor que compara.
     */
    private const MODEL = FiscalModel::Nfe;

    /**
     * O status que a taxonomia recebe daqui é sempre o mesmo, e ele é inerte:
     * o transporte já recusou toda resposta fora do `2xx` — cada uma delas com o
     * status real dentro da exceção —, então o `classify()` deste arquivo só
     * decide pelo `cStat`, e o ramo que olha o status (`0` ou `5xx`, que é
     * "não houve resposta") já foi tomado no transporte, com o status verdadeiro.
     */
    private const HTTP_OK = 200;

    public function __construct(
        private DfeSoapEnvelope $envelope,
        private DfeTransport $transport,
        private DfeEntryCollector $collector,
        private FiscalLookupBudget $lookupBudget,
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
        // O veredito do certificado vem antes de qualquer montagem: um cliente
        // sem A1 não produz envelope, não passa pelo XSD e não gasta banda.
        $this->transport->requireCertificate($client);

        $parsed = $this->send($client, $fromNsu);

        // O status HTTP real entrou na classificação dentro do transporte, que
        // é quem recusa o que não for `2xx` — com o status verdadeiro, e não
        // com o `cStat` de um corpo que esse status não traz. O que sobra para
        // aqui é o `cStat`, e `self::HTTP_OK` diz o que ele é.
        $failure = FiscalFailure::classify(self::HTTP_OK, $parsed->cStat);

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
                // A pausa é a mesma nas duas, então o rótulo é o que separa o
                // esfriamento normal do bloqueio que é problema do cliente. É a
                // palavra da taxonomia, nunca o `xMotivo`: a coluna que a
                // recebe é lida pelo painel e não carrega texto do fisco.
                failure: $failure,
            );
        }

        throw new FiscalException(
            $parsed->xMotivo === '' ? 'O serviço de distribuição rejeitou a consulta.' : $parsed->xMotivo,
            $failure,
        );
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
     * método só: a reserva do teto **antes** de qualquer chamada, a mesma
     * leitura da resposta e a mesma regra de `null`.
     *
     * A reserva vem antes da requisição e não depois de um resultado: o fisco
     * conta a consulta que saiu, e a que não saiu por falta de teto é
     * justamente a que ele não pode contar. Adiar é `FiscalLookupDeferred`, e
     * não retentativa nem silêncio: quem chama decide o que fazer com a posição
     * que ficou sem resposta.
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

        $parsed = $ask();

        $failure = FiscalFailure::classify(self::HTTP_OK, $parsed->cStat);

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
        // não deu para ler, e isso não é a mesma coisa que o serviço não ter
        // documento naquela posição. Devolver `null` aqui diria que a posição
        // está vazia, e quem reconcilia contaria a consulta como feita e
        // seguiria para a próxima, com o buraco intacto e o limite horário de
        // consultas gasto.
        $refused = $result->failures[0];

        throw new RuntimeException("A resposta do serviço traz uma entrada que não pôde ser lida na posição {$refused->nsu}: {$refused->reason}");
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

    private function collect(DfeResponse $parsed): PullResult
    {
        return $this->collector->collect($parsed, self::MODEL);
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
     * A UF que não está na tabela é defeito de cadastro, e a consulta também não
     * sai por causa disso — a classe é a da família, com nome próprio para que o
     * log diga qual das duas recusas aconteceu.
     */
    private function ufCodeOf(Client $client): string
    {
        $acronym = strtoupper(trim((string) $client->state));
        $code = self::UF_CODES[$acronym] ?? null;

        if ($code === null) {
            throw new FiscalClientStateUnknown("UF do cliente {$client->tax_id} não está na tabela de UFs: '{$client->state}'.");
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

    private function blockUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60));
    }
}
