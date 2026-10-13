<?php

namespace App\Services\Fiscal\Nfse;

use App\Enums\FiscalModel;
use App\Services\Fiscal\Contracts\FailedEntry;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Support\DocZipDecoder;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Services\Fiscal\Support\NotIndexableDocument;
use RuntimeException;

/**
 * A conversão das entradas do lote da ADN em documentos: cada payload vira XML,
 * o XML vira identidade, e uma entrada que não vira documento vira
 * `FailedEntry` — nunca silêncio, e nunca o fim do lote.
 *
 * É o irmão de `DfeEntryCollector` com uma diferença de entrada: o payload da
 * ADN pode chegar como XML cru **ou** encapsulado (base64, com ou sem
 * compressão gZip/zlib — a forma exata é uma das coisas que o probe confirma).
 * A bifurcação vive em `decodePayload()`, e o caminho comprimido reutiliza o
 * `DocZipDecoder`, que já cobre os três containers com testes próprios.
 *
 * O modelo pedido é sempre `FiscalModel::Nfse`: a família da NFS-e nacional é
 * unitária para este conector, e quem confere a identidade — dígito verificador
 * de 50 posições e guarda de família — é `FiscalXmlMetadata`, entrada a entrada.
 *
 * Esta classe não escreve no banco: `FiscalDocumentWriter` é o único caminho de
 * gravação, e a ordem de `documents` é a ordem em que o serviço entregou.
 */
final class NfseAdnEntryCollector
{
    public function __construct(
        private DocZipDecoder $decoder,
        private FiscalXmlMetadata $metadata,
    ) {}

    /**
     * Lê o lote entrada a entrada, e uma entrada que não vira documento não
     * interrompe as outras: a posição ilegível é um buraco a reconciliar, não o
     * fim da fila. O que decide o cursor é `mayAdoptPosition`, e é por isso que
     * a recusa de uma entrada tem de aparecer no retorno em vez de sumir.
     *
     * @param  list<array{nsu: int, payload: string, schema: string}>  $itens
     * @return array{documents: list<PulledDocument>, failures: list<FailedEntry>}
     */
    public function collect(array $itens): array
    {
        $documents = [];
        $failures = [];

        foreach ($itens as $item) {
            try {
                $xml = $this->decodePayload($item['payload']);
            } catch (RuntimeException) {
                $failures[] = new FailedEntry(
                    nsu: $item['nsu'],
                    schema: $item['schema'],
                    reason: 'O payload da entrada não pôde ser decodificado.',
                );

                continue;
            }

            try {
                $extracted = $this->metadata->extract($xml, FiscalModel::Nfse);
            } catch (NotIndexableDocument) {
                // A entrada é uma coisa que o parser reconheceu e que não é um
                // documento a indexar. Ela **não** vira recusa, pelo mesmo
                // motivo de `DfeEntryCollector`: recusar aqui puniria a posição
                // por `mayAdoptPosition` falso, e a mesma entrada viria na
                // consulta seguinte, na seguinte e na seguinte. Pular é o que
                // deixa a posição passar.
                continue;
            } catch (RuntimeException) {
                // A chave com dígito verificador inválido, a chave de outra
                // família e o XML ilegível chegam todos aqui, e em nenhum deles
                // houve o suficiente para guardar o documento.
                $failures[] = new FailedEntry(
                    nsu: $item['nsu'],
                    schema: $item['schema'] !== '' ? $item['schema'] : 'payload-decodificado',
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
                nsu: $item['nsu'],
                // O que a ADN declarou prevalece; sem declaração, a raiz que o
                // parser reconheceu é a melhor descrição que existe.
                schema: $item['schema'] !== '' ? $item['schema'] : $extracted->schema,
                emissaoAt: $extracted->emissaoAt,
                eventoOcorridoEmAt: $extracted->eventoOcorridoEmAt,
                xml: $xml,
                mascarado: $extracted->mascarado,
                numero: $extracted->numero,
                serie: $extracted->serie,
            );
        }

        return ['documents' => $documents, 'failures' => $failures];
    }

    /**
     * A forma do payload é uma das hipóteses do contrato REST que o canário
     * confirma: o manual sugere XML dentro do JSON, possivelmente encapsulado.
     * O teste do começo do dado decide o caminho — XML cru entra direto, e
     * tudo o mais é matéria do `DocZipDecoder`, que recusa com erro nomeado o
     * container que não conhece.
     */
    private function decodePayload(string $payload): string
    {
        if (str_starts_with(ltrim($payload), '<')) {
            return $payload;
        }

        return $this->decoder->decode($payload);
    }
}
