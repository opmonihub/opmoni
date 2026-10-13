<?php

namespace App\Services\Fiscal\Nfse;

use App\Enums\FiscalFailure;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A leitura da resposta da ADN contribuintes: o que ela vira, e a fronteira
 * entre resultado e exceção.
 *
 * ⚠️ O SHAPE DO JSON É HIPÓTESE. Este checkout já viu o Swagger publicado e
 * **nenhuma** resposta real: os nomes dos campos, o formato do NSU e os
 * códigos de "nenhum documento" são o que o manual sugere, e o
 * `fiscal:nfse-probe` é o comando que confirma. Por isso todo o acesso a
 * campo está isolado nos métodos de leitura (`itensDe()`, `maxNsuDe()`,
 * `corpoDeNegocio()`), com os nomes candidatos em constantes de classe —
 * ajustar o contrato depois do probe é mudar uma lista, não espalhar chaves
 * por um conector (ver `add-nfse-adn-capture/design.md`, decisão 5).
 *
 * Quatro regras, irmãs das de `DfePullReader`:
 *
 * 1. **O corpo vem antes do status HTTP.** Um `404` com corpo de negócio é
 *    resposta do serviço — "não há documento naquela posição" é um desfecho
 *    classificável, não uma queda de rede. Só um corpo que não é resposta
 *    nenhuma do serviço (HTML de proxy, página de erro) deixa o status falar,
 *    e aí a taxonomia classifica pelo status real.
 * 2. **Rejeição é estado, não exceção.** "Nenhum documento" — lote vazio em
 *    `2xx` ou corpo de negócio sem itens — volta como `PullResult` com
 *    `blockedUntil` de uma hora e a posição intacta: o que a resposta devolve
 *    nesse caso é o eco da posição pedida, e adotá-la seria sobrescrever o
 *    cursor com o valor anterior.
 * 3. **A posição nunca é somada.** A posição do lote é a **maior NSU devolvida
 *    nos itens** — a ADN não publica `UltimoNSU` no corpo como a distribuição
 *    SOAP faz —, e um lote sem itens não adota posição nenhuma.
 * 4. **Uma posição que não virou documento não é posição vazia.** Entrada
 *    entregue que o parse recusou vira `FailedEntry` e segura a posição
 *    (`mayAdoptPosition` falso), que é o que manda o serviço reentregá-la.
 */
final class NfseAdnPullReader
{
    /**
     * Onde a lista de documentos do lote está. A forma real foi observada pelo
     * `fiscal:nfse-probe` no canário Auto Center (HTTP 200,
     * `StatusProcessamento: DOCUMENTOS_LOCALIZADOS`): a lista é `LoteDFe`, ao
     * lado de `Alertas` e `Erros`, também listas.
     */
    private const CHAVES_DA_LISTA = ['LoteDFe'];

    /**
     * Onde o NSU de cada item está. O canário confirmou `NSU` em maiúsculas.
     */
    private const CHAVES_DE_NSU = ['NSU', 'nsu'];

    /**
     * Onde o payload do documento de cada item está. O canário confirmou
     * `ArquivoXml` com o XML cru — sem base64, sem gzip.
     */
    private const CHAVES_DE_PAYLOAD = ['ArquivoXml'];

    /**
     * Onde o tipo declarado do item está. O canário confirmou `TipoDocumento`
     * com `NFSE` e `EVENTO`.
     */
    private const CHAVES_DE_SCHEMA = ['TipoDocumento'];

    /**
     * Onde a maior posição que o serviço conhece pode estar. A ADN não publica
     * `UltimoNSU` como a distribuição SOAP; se um campo equivalente existir,
     * é um destes nomes — e `null` é o caso comum, que não é fila vazia.
     */
    private const CHAVES_DE_MAX = ['maxNSU', 'maxNsu', 'max_nsu'];

    public function __construct(private NfseAdnEntryCollector $collector) {}

    /**
     * Resposta da captura incremental: o lote, a pausa de uma hora, ou a
     * exceção do que não é resposta de serviço.
     *
     * `$fromNsu` é a posição que o chamador tem: é o eco que o resultado
     * carrega quando a resposta não entrega documento nenhum — a posição
     * armazenada fica intacta, que é a regra do "nenhum documento localizado".
     */
    public function read(Response $response, int $fromNsu): PullResult
    {
        $corpo = $this->corpoDeNegocio($response);

        if ($corpo === null) {
            // O corpo não é resposta do serviço: página de erro do proxy, HTML
            // do balanceador. O status HTTP real é quem classifica, e `2xx`
            // com um corpo assim é defeito do parse — `Rejected`, que não
            // adianta repetir.
            throw new FiscalException(
                'A ADN respondeu fora do contrato do serviço.',
                FiscalFailure::classify($response->status(), ''),
            );
        }

        $itens = $this->itensDe($corpo);

        // "Nenhum documento" / fim de fila — no `2xx` sem itens e no `404` com
        // corpo de negócio igualmente: o que a resposta diz nas duas é "pare
        // uma hora". A posição não é adotada, porque o eco da posição pedida
        // não é posição nova.
        if ($itens === []) {
            return new PullResult(
                documents: [],
                lastNsu: $fromNsu,
                maxNsu: $this->maxNsuDe($corpo),
                more: false,
                blockedUntil: $this->blockUntil(),
                mayAdoptPosition: false,
                failure: FiscalFailure::NoDocuments,
            );
        }

        $lido = $this->collector->collect($itens);

        // A posição do lote é a maior NSU que o serviço devolveu — nunca a
        // local somada de um, e nunca uma posição que a resposta não trouxe.
        $lastNsu = max(array_map(fn (array $item): int => $item['nsu'], $itens));
        $maxNsu = $this->maxNsuDe($corpo);

        return new PullResult(
            documents: $lido['documents'],
            lastNsu: $lastNsu,
            maxNsu: $maxNsu,
            more: $maxNsu !== null && $lastNsu < $maxNsu,
            blockedUntil: null,
            // Havendo entrada entregue que não virou documento, a posição não
            // é adotada: a próxima consulta volta a pedir a partir da posição
            // anterior e tenta ler a entrada de novo.
            mayAdoptPosition: $lido['failures'] === [],
            failures: $lido['failures'],
        );
    }

    /**
     * Resposta da consulta pontual: o documento da posição pedida, `null`
     * quando o serviço diz que não há documento nela — o `404` com corpo de
     * negócio incluído —, e exceção para o resto.
     *
     * A assimetria com `read()` é a forma, não a regra, e é a mesma de
     * `DfePullReader::readOne()`: a captura registra o "nada novo" no cursor, e
     * a consulta por posição não tem onde registrar nada.
     */
    public function readOne(Response $response): ?PulledDocument
    {
        $corpo = $this->corpoDeNegocio($response);

        if ($corpo === null) {
            throw new FiscalException(
                'A ADN respondeu fora do contrato do serviço.',
                FiscalFailure::classify($response->status(), ''),
            );
        }

        $itens = $this->itensDe($corpo);

        if ($itens === []) {
            return null;
        }

        $lido = $this->collector->collect($itens);

        if ($lido['documents'] !== []) {
            return $lido['documents'][0];
        }

        // Nem documento nem recusa: não há o que recusar com, e a ausência é o
        // que fecha o laço na reconciliação — ver `DfePullReader::readOne()`.
        if ($lido['failures'] === []) {
            return null;
        }

        // Uma resposta "com documento" cujo conteúdo não pôde ser lido não é
        // ausência: devolvê-la como `null` marcaria a lacuna como resolvida sem
        // que nada tenha sido resolvido.
        $recusada = $lido['failures'][0];

        throw new RuntimeException("A resposta do serviço traz uma entrada que não pôde ser lida na posição {$recusada->nsu}: {$recusada->reason}");
    }

    /**
     * O corpo como resposta de negócio da ADN, ou `null` quando o que veio
     * não é um JSON de serviço. Um array **é** resposta de negócio — mesmo
     * vazio, mesmo com status enganoso —, e é essa igualdade que faz do `404`
     * com corpo uma resposta classificável e não uma exceção de rede.
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    private function corpoDeNegocio(Response $response): ?array
    {
        $decodificado = json_decode($response->body(), true);

        return is_array($decodificado) ? $decodificado : null;
    }

    /**
     * As entradas do lote, na forma que o coletor consome. Público porque o
     * `fiscal:nfse-probe` imprime contagem e NSU por item sem passar pelo
     * parse do documento — e é justamente essa leitura que o probe confirma.
     *
     * A hipótese inteira mora aqui: lista aninhada sob um dos nomes de
     * `CHAVES_DA_LISTA` (ou o próprio corpo, quando ele já é a lista), NSU e
     * payload sob os nomes de `CHAVES_DE_NSU` e `CHAVES_DE_PAYLOAD`. Item sem
     * NSU legível vira posição `0` — que é recusa registrada, nunca silêncio.
     *
     * @param  array<string, mixed>|list<mixed>  $corpo
     * @return list<array{nsu: int, payload: string, schema: string}>
     */
    public function itensDe(array $corpo): array
    {
        $lista = $this->listaDe($corpo);

        $itens = [];

        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itens[] = [
                'nsu' => $this->inteiroDe($item, self::CHAVES_DE_NSU),
                'payload' => (string) $this->campoDe($item, self::CHAVES_DE_PAYLOAD),
                'schema' => (string) $this->campoDe($item, self::CHAVES_DE_SCHEMA),
            ];
        }

        return $itens;
    }

    /**
     * A maior posição que o serviço conhece, quando a resposta a traz — `null`
     * é o caso comum, e nulo não é fila vazia.
     *
     * @param  array<string, mixed>|list<mixed>  $corpo
     */
    public function maxNsuDe(array $corpo): ?int
    {
        $max = $this->campoDe($corpo, self::CHAVES_DE_MAX);

        return $max === null ? null : (int) $max;
    }

    /**
     * A lista de itens dentro do corpo, sob o primeiro nome conhecido — ou o
     * próprio corpo, quando ele já é a lista.
     *
     * @param  array<string, mixed>|list<mixed>  $corpo
     * @return list<mixed>
     */
    private function listaDe(array $corpo): array
    {
        if (array_is_list($corpo)) {
            return $corpo;
        }

        foreach (self::CHAVES_DA_LISTA as $chave) {
            $valor = $corpo[$chave] ?? null;

            if (is_array($valor) && array_is_list($valor)) {
                return $valor;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $candidatos
     */
    private function campoDe(array $item, array $candidatos): mixed
    {
        foreach ($candidatos as $chave) {
            if (array_key_exists($chave, $item) && $item[$chave] !== null) {
                return $item[$chave];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $candidatos
     */
    private function inteiroDe(array $item, array $candidatos): int
    {
        $valor = $this->campoDe($item, $candidatos);

        return $valor === null ? 0 : (int) $valor;
    }

    /**
     * A pausa do fim de fila é a mesma hora do bloqueio por consumo indevido:
     * é o `fiscal.block_minutes`, e não um número escrito aqui, quem guarda a
     * regra — retomar antes zera a contagem do fisco e a reinicia.
     */
    private function blockUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60));
    }
}
