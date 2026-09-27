<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Support\DigValComparison;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * O único caminho de escrita do módulo.
 *
 * A identidade é a chave composta `(client_id, chave_acesso, event_id)`: o
 * mesmo lote reprocessado sobrescreve e nunca duplica, e as várias etapas da
 * distribuição que chegam sob a mesma chave de acesso — resumo, documento
 * completo, evento — continuam convivendo. Um `event_id` não-nulo defaultando a
 * string vazia é o que torna isso possível no banco: nele `NULL != NULL`, e uma
 * coluna nullable deixaria passar quantas duplicatas de documento comum
 * quisesse, em silêncio.
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
            'event_id' => $document->eventId,
        ]);

        // O veredito sai da linha que já existia, e é por isso que a comparação
        // acontece antes do `fill()`: depois dele `$row->digval` já seria o
        // digest que está entrando, e a comparação seria o documento consigo
        // mesmo — sempre verdadeira.
        $digvalConfere = DigValComparison::compare($row->digval, $document->digVal);

        // O digest gravado é o do XML que está no arquivo, nunca o de uma
        // entrega anterior: um digest que não descreve o arquivo apontado seria
        // uma afirmação falsa gravada ao lado de uma verdade.
        $row->fill([
            'source' => $source,
            'model' => $document->model,
            'kind' => $document->kind,
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
            'xml_bytes' => strlen($document->xml),
            'captured_at' => now(),
        ]);

        // `account_id` fora do `fill()` de propósito: ele não é mass-assignável
        // nos modelos de tenant deste módulo, e o hook de criação o puxaria da
        // conta corrente. A dona do documento é a conta do cliente, e é isso que
        // fica gravado — inclusive se a captura rodar sem tenant em volta.
        $row->account_id = $client->account_id;

        $row->save();

        return $row;
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
        );
    }
}
