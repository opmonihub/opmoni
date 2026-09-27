<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;

/**
 * A identidade e os campos de leitura do documento capturado. `eventId` é
 * `tpEvento-nSeqEvento` para evento e a string vazia para documento, que é o
 * valor neutro da restrição de unicidade.
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
        public ?CarbonImmutable $emissaoAt,
        public ?CarbonImmutable $eventoOcorridoEmAt,
    ) {}
}
