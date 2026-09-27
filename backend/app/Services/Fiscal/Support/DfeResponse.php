<?php

namespace App\Services\Fiscal\Support;

/**
 * A resposta do serviço de distribuição. `ultNsu` é a posição devolvida — nunca
 * incrementada — e `maxNsu` é nulo quando a resposta não o traz.
 */
final readonly class DfeResponse
{
    /**
     * @param  list<DfeEntry>  $entries
     */
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public int $ultNsu,
        public ?int $maxNsu,
        public array $entries,
    ) {}
}
