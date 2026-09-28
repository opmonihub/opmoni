<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalStage;
use Carbon\CarbonImmutable;

/**
 * A identidade e os campos de leitura do documento capturado.
 *
 * `stage` é onde a entrega caiu na cadeia da distribuição — resumo, documento
 * completo ou evento —, e é o que separa o resumo do documento autorizado, que
 * são o mesmo documento e chegam sob a mesma chave de acesso.
 *
 * `eventId` é
 * `tpEvento-nSeqEvento` para evento e a string vazia para documento, que é o
 * valor neutro da restrição de unicidade.
 *
 * `digVal` é o SHA-1 em base64 que o ambiente nacional calculou sobre o XML do
 * documento — no topo para o resumo, em `protNFe/infProt` para o documento
 * autorizado. Nulo quando o XML não o traz, que é o caso do evento.
 *
 * `mascarado` diz que as chaves dos documentos que este transporta chegaram
 * substituídas pelo preenchimento que o fisco usa para uma referência que não é
 * do consultante. É a diferença entre "o documento não referencia as NF-e que
 * transporta" e "o documento referencia, e o fisco não disse quais" — e a
 * coluna existe para o painel poder distinguir as duas, porque só o parser sabe
 * qual das duas aconteceu e o writer não reparseia o XML.
 */
final readonly class FiscalXmlMetadataResult
{
    public function __construct(
        public string $chave,
        public FiscalModel $model,
        public FiscalKind $kind,
        public FiscalStage $stage,
        public string $eventId,
        public string $schema,
        public ?string $emitenteCnpj,
        public ?string $destinatarioCnpj,
        public ?string $valorTotal,
        public ?string $digVal,
        public ?CarbonImmutable $emissaoAt,
        public ?CarbonImmutable $eventoOcorridoEmAt,
        public bool $mascarado,
    ) {}
}
