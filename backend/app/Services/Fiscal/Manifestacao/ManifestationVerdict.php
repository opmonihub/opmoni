<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalManifestationOutcome;

/**
 * O veredito de um evento que o serviço processou: o que vai para o registro
 * de manifestação — o outcome, o cStat e o `xMotivo` já condensado.
 */
final readonly class ManifestationVerdict
{
    public function __construct(
        public FiscalManifestationOutcome $outcome,
        public string $cStat,
        public string $xMotivo,
    ) {}
}
