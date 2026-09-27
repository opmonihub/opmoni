<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;

/**
 * A identidade e os campos de leitura do documento capturado. `eventId` é
 * `tpEvento-nSeqEvento` para evento e a string vazia para documento, que é o
 * valor neutro da restrição de unicidade.
 *
 * `digVal` é o SHA-1 em base64 que o ambiente nacional calculou sobre o XML do
 * documento — no topo para o resumo, em `protNFe/infProt` para o documento
 * autorizado. Nulo quando o XML não o traz, que é o caso do evento.
 */
final readonly class FiscalXmlMetadataResult
{
    public function __construct(
        public string $chave,
        public FiscalModel $model,
        public FiscalKind $kind,
        public string $eventId,
        public string $schema,
        public ?string $emitenteCnpj,
        public ?string $destinatarioCnpj,
        public ?string $valorTotal,
        public ?string $digVal,
        public ?CarbonImmutable $emissaoAt,
        public ?CarbonImmutable $eventoOcorridoEmAt,
    ) {}
}
