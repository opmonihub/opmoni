<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Support\DigValComparison;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * O único caminho de escrita do módulo.
 *
 * A identidade é a chave composta `(client_id, chave_acesso, stage, event_id)`: o
 * mesmo lote reprocessado sobrescreve e nunca duplica, e as três entregas da
 * distribuição que chegam sob a mesma chave de acesso — resumo, documento
 * completo, evento — convivem como três linhas. Um `event_id` não-nulo defaultando
 * a string vazia é o que torna isso possível no banco: nele `NULL != NULL`, e uma
 * coluna nullable deixaria passar quantas duplicatas de documento comum quisesse,
 * em silêncio.
 *
 * A etapa entrou na chave porque `event_id` sozinho não separava o resumo do
 * documento completo: nenhum dos dois é evento, os dois têm `event_id` vazio, e o
 * segundo sobrescrevia o XML, a posição e o digest do primeiro.
 */
final class FiscalDocumentWriter
{
    /**
     * `tpEvento` com seis dígitos, `-`, e o `nSeqEvento` com até dez — a
     * tipagem da NT. O grupo é opcional porque a string vazia é o valor neutro
     * do documento comum, o mesmo que a coluna `event_id` guarda por padrão.
     */
    private const EVENT_ID_PATTERN = '/^(?:\d{6}-\d{1,10})?$/';

    public function store(Client $client, FiscalSource $source, PulledDocument $document): FiscalDocument
    {
        $path = $this->pathOf($client, $document);

        // O arquivo antes da linha, sempre. Uma falha de banco depois deste
        // `put` deixa um arquivo órfão, que a próxima captura sobrescreve sem
        // consequência; a ordem inversa deixa uma linha apontando para um
        // arquivo que não existe, e o download dá 404 num documento que o
        // painel já está contando.
        Storage::disk('fiscal')->put($path, $document->xml);

        // `updateOrCreate` é este `firstOrNew()->fill()->save()`, escrito à mão
        // porque o veredito do `digVal` precisa da linha que já existia: uma
        // segunda consulta abriria uma janela entre ler o digest e gravar, e
        // `updateOrCreate` a perde.
        $row = FiscalDocument::firstOrNew([
            'client_id' => $client->getKey(),
            'chave_acesso' => $document->chave,
            'stage' => $document->stage,
            'event_id' => $document->eventId,
        ]);

        // A comparação é com a **outra** etapa da mesma chave de acesso, nunca
        // com a linha que está sendo gravada. Ler o digest da própria linha
        // comparava o documento com ele mesmo: o veredito só valia na primeira
        // escrita, e um reprocessamento do mesmo lote — caminho normal, não
        // exceção — devolvia `true` para o documento que acabara de divergir,
        // apagando o achado. Lendo a etapa parceira, que nunca é a linha sendo
        // escrita, o veredito é estável em qualquer ordem de chegada: um par que
        // bate continua batendo, e um que diverge continua divergindo.
        $digvalConfere = DigValComparison::compare(
            $this->counterpartDigest($client, $document),
            $document->digVal,
        );

        // O digest gravado é o do XML que está no arquivo, nunca o de uma
        // entrega anterior: um digest que não descreve o arquivo apontado seria
        // uma afirmação falsa gravada ao lado de uma verdade.
        $row->fill([
            'source' => $source,
            'model' => $document->model,
            'kind' => $document->kind,
            'stage' => $document->stage,
            'nsu' => $document->nsu,
            'emitente_cnpj' => $document->emitenteCnpj,
            'destinatario_cnpj' => $document->destinatarioCnpj,
            'valor_total' => $document->valorTotal,
            'emissao_at' => $document->emissaoAt,
            'evento_ocorrido_em_at' => $document->eventoOcorridoEmAt,
            'schema' => $document->schema,
            'storage_path' => $path,
            'sha256' => hash('sha256', $document->xml),
            'digval' => $document->digVal,
            'digval_confere' => $digvalConfere,
            // Vem do parser e nunca é inferido do NSU, do `schema` declarado nem
            // da etapa: nenhum dos três diz se o fisco substituiu as chaves dos
            // documentos transportados, e uma linha gravada dizendo `false`
            // quando o documento chegou mascarado é uma afirmação falsa ao lado
            // de uma verdade.
            'mascarado' => $document->mascarado,
            'xml_bytes' => strlen($document->xml),
            'captured_at' => now(),
        ]);

        // `account_id` fora do `fill()` de propósito: ele não é mass-assignável
        // nos modelos de tenant deste módulo, e o hook de criação o puxaria da
        // conta corrente. A dona do documento é a conta do cliente, e é isso que
        // fica gravado — inclusive se a captura rodar sem tenant em volta.
        $row->account_id = $client->account_id;

        $row->save();

        // Só depois do `save()`: a linha existe, o documento está em disco e
        // então há algo para relatar. Se o `save()` falhar, o log mentiria.
        if ($digvalConfere === false) {
            $this->reportDivergence($client, $document);
        }

        return $row;
    }

    /**
     * Uma divergência de `digVal` é o achado que a decisão 8 existe para produzir,
     * e uma coluna que ninguém lê não marca nada: o documento segue no banco, o
     * painel o conta como normal e o download o serve como se estivesse
     * íntegra. O log é o mínimo honesto que dá conta da ocorrência.
     *
     * **Warning, e não error:** nada falhou. A captura terminou, o lote está
     * gravado e a posição pode avançar — mas alguém pode estar lendo um XML que
     * o ambiente nacional não atestou. A exceção seria o tratamento errado: um
     * `error` aqui convida o retry, e repetir a mesma entrega de novo não muda
     * digest nenhum.
     *
     * **O que não entra:** nem o XML, nem o `docZip`, nem a senha do
     * certificado, nem os dois valores de digest. O log diz que houve
     * divergência — que é o sinal inteiro — e quem for atrás do documento tem a
     * linha, com os dois digests nela. A chave de acesso entra porque é
     * identificador fiscal público, e sem ela o log não diz de qual documento
     * se trata.
     *
     * O que fazer depois do log é decisão de outra tarefa: o design deixa
     * explícito que a reação à rejeição é aberta, e um alerta aqui seria
     * decidir isso no lugar de quem ainda não decidiu.
     */
    private function reportDivergence(Client $client, PulledDocument $document): void
    {
        Log::warning('fiscal.capture.digval_divergente', [
            'account_id' => (int) $client->account_id,
            'client_id' => (int) $client->getKey(),
            'chave_acesso' => $document->chave,
            'nsu' => $document->nsu,
        ]);
    }

    /**
     * O digest da etapa parceira da mesma chave de acesso, ou `null` quando ela
     * ainda não chegou — o caso de toda captura que começa no meio da fila, do
     * evento e da consulta por chave que devolveu só o XML completo.
     *
     * A busca é pela etapa que faz par com a que está chegando (`counterpart()`),
     * e nunca pela própria: a linha da etapa parceira é a única que não é a linha
     * que este `store()` vai sobrescrever.
     */
    private function counterpartDigest(Client $client, PulledDocument $document): ?string
    {
        $counterpart = $document->stage->counterpart();

        if ($counterpart === null) {
            return null;
        }

        return FiscalDocument::query()
            ->where('client_id', $client->getKey())
            ->where('chave_acesso', $document->chave)
            ->where('stage', $counterpart)
            ->value('digval');
    }

    /**
     * O caminho é derivado, não higienizado.
     *
     * `FiscalXmlPath` interpola a chave de acesso e o `event_id` no caminho, e a
     * coluna resultante é lida de volta por quem serve o download. Reescrever um
     * segmento em silêncio mudaria para qual arquivo a identidade do documento
     * aponta, e o defeito apareceria como um documento baixando o XML de outro —
     * sem erro em lugar nenhum. Então o writer recusa: chave fora do formato
     * validado, ou `event_id` fora da tipagem da NT, e nada é gravado.
     *
     * É a guarda que importa porque o `event_id` é montado com `tpEvento` e
     * `nSeqEvento` lidos do XML, e um dos dois adulterado atravessa a validação
     * de dígito verificador, que protege a chave e não o evento.
     */
    private function pathOf(Client $client, PulledDocument $document): string
    {
        if (! FiscalXmlMetadata::isValidChave($document->chave)) {
            throw new RuntimeException("Chave de acesso fora do formato esperado antes de virar caminho: {$document->chave}.");
        }

        if (preg_match(self::EVENT_ID_PATTERN, $document->eventId) !== 1) {
            throw new RuntimeException("Identificador de evento fora da tipagem da NT antes de virar caminho: '{$document->eventId}'.");
        }

        return FiscalXmlPath::for(
            (int) $client->account_id,
            (int) $client->getKey(),
            $document->chave,
            $document->eventId,
            $document->stage,
        );
    }
}
