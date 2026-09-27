<?php

namespace App\Services\Fiscal\Support;

/**
 * Um documento distribuído dentro do lote, ainda comprimido: `payload` é o
 * `docZip` cru e só vira XML na borda da captura.
 */
final readonly class DfeEntry
{
    public function __construct(
        public int $nsu,
        public string $schema,
        public string $payload,
    ) {}
}
