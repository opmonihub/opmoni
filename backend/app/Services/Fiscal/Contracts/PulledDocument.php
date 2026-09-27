<?php

namespace App\Services\Fiscal\Contracts;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;

/**
 * Um documento que o serviço de distribuição entregou, já decodificado e já
 * com a identidade extraída do XML.
 *
 * É a fronteira entre o conector e a gravação. O conector entrega o documento
 * e o `FiscalDocumentWriter` grava: o conector nunca escreve no banco, e por
 * isso este objeto não carrega `client_id`, `account_id` nem `source` — quem
 * chamou sabe os três, e o `source` vem do próprio conector.
 */
final readonly class PulledDocument
{
    /**
     * `eventId` é a string vazia no documento comum e `tpEvento-nSeqEvento` no
     * evento, e é o que fecha a chave composta
     * `(client_id, chave, event_id)`.
     *
     * `emitenteCnpj`, `destinatarioCnpj` e `valorTotal` são as três colunas de
     * metadado que a camada de parse já extraiu, e atravessam o contrato para
     * que o `FiscalDocumentWriter` não reparseie `$xml` para preenchê-las: uma
     * segunda leitura do mesmo XML produziria uma segunda fonte para os mesmos
     * três valores, na camada que grava. Nulos quando o XML não os traz, que é
     * o caso comum em evento e em resumo.
     *
     * `nsu` é a posição desta entrega, não a do documento: resumo, documento
     * completo e evento chegam em posições diferentes e os três coexistem.
     *
     * `xml` são os bytes crus do payload, ainda não normalizados — a regra do
     * módulo é guardar o bruto e normalizar só na leitura.
     *
     * As duas datas são independentes porque a posição não correlaciona com
     * tempo: um evento pode chegar antes da própria nota.
     */
    public function __construct(
        public FiscalModel $model,
        public FiscalKind $kind,
        public string $chave,
        public string $eventId,
        public ?string $emitenteCnpj,
        public ?string $destinatarioCnpj,
        public ?string $valorTotal,
        public int $nsu,
        public string $schema,
        public ?CarbonImmutable $emissaoAt,
        public ?CarbonImmutable $eventoOcorridoEmAt,
        public string $xml,
    ) {}
}
