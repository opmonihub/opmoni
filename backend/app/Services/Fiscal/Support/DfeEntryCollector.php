<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalModel;
use App\Services\Fiscal\Contracts\FailedEntry;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use RuntimeException;

/**
 * A conversão de um lote de distribuição em documentos: cada `docZip` vira XML,
 * o XML vira identidade, e uma entrada que não vira documento vira
 * `FailedEntry` — nunca silêncio, e nunca o fim do lote.
 *
 * Nada aqui sabe qual serviço respondeu: o lote já vem interpretado e o modelo
 * documento chega como argumento, porque o modelo do lote é o do conector que
 * perguntou e cada documento ainda é conferido contra ele. Um `resCTe` entregue
 * ao conector de NF-e é recusado aqui, entrada a entrada, porque a chave é de
 * outro documento.
 *
 * Esta classe não escreve no banco: `FiscalDocumentWriter` é o único caminho de
 * gravação, e a ordem de `documents` é a ordem em que o serviço entregou.
 */
final class DfeEntryCollector
{
    public function __construct(
        private DocZipDecoder $decoder,
        private FiscalXmlMetadata $metadata,
    ) {}

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
    public function collect(DfeResponse $parsed, FiscalModel $model): PullResult
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
                $extracted = $this->metadata->extract($xml, $model);
            } catch (NotIndexableDocument) {
                // A entrada é uma coisa que o parser reconheceu e que não é um
                // documento a indexar. Ela **não** vira recusa, e é o que a
                // distingue de um buraco: recusa aqui puniria a posição por
                // `mayAdoptPosition` falso, e a mesma entrada viria na consulta
                // seguinte, na seguinte e na seguinte — uma inutilização no meio
                // do lote travaria o cliente sem que nada disso virasse erro.
                // Pular é o que deixa a posição passar: não havia documento
                // naquela posição, e não havia documento para perder.
                continue;
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
                stage: $extracted->stage,
                chave: $extracted->chave,
                eventId: $extracted->eventId,
                emitenteCnpj: $extracted->emitenteCnpj,
                destinatarioCnpj: $extracted->destinatarioCnpj,
                valorTotal: $extracted->valorTotal,
                digVal: $extracted->digVal,
                nsu: $entry->nsu,
                schema: $entry->schema,
                emissaoAt: $extracted->emissaoAt,
                eventoOcorridoEmAt: $extracted->eventoOcorridoEmAt,
                xml: $xml,
                // Do parser, sem inferência: `nsu` e `schema` são o que o
                // serviço declarou, e nenhum dos dois diz se o fisco mascarou as
                // chaves transportadas deste documento.
                mascarado: $extracted->mascarado,
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
            // A entrada pulada por não ser documento não é buraco e não entra
            // aqui — é o que impede que uma recusa que nunca passa, porque o
            // serviço a entrega sempre, trave a posição para sempre.
            mayAdoptPosition: $failures === [],
            failures: $failures,
        );
    }
}
