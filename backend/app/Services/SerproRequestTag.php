<?php

namespace App\Services;

use InvalidArgumentException;

final class SerproRequestTag
{
    /**
     * Identificador opcional de requisição, lido pelo provedor no relatório de
     * consumo. É texto livre do lado dele, então o formato é uma convenção
     * nossa e precisa ser estável.
     *
     * T + 14 (autor) + T + 14 (contribuinte) + 2 (sequencial) = 32.
     */
    public function build(string $autor, string $contribuinte, int $serviceSequence): string
    {
        if ($serviceSequence < 0 || $serviceSequence > 99) {
            throw new InvalidArgumentException('O sequencial do serviço deve estar entre 0 e 99.');
        }

        return $this->tipo($autor)
            .$this->documento($autor)
            .$this->tipo($contribuinte)
            .$this->documento($contribuinte)
            .str_pad((string) $serviceSequence, 2, '0', STR_PAD_LEFT);
    }

    private function documento(string $value): string
    {
        return str_pad(strtoupper($value), 14, '0');
    }

    /**
     * O número do tipo vem de `SerproEnvelope::tipo()` — a fonte única da regra
     * 11→1, 14→2 — e a guarda de comprimento é deste lado: o tag não tem o que
     * fazer com um documento de outro tamanho, e o envelope, sim.
     */
    private function tipo(string $value): string
    {
        $documento = strtoupper($value);

        if (strlen($documento) !== 11 && strlen($documento) !== 14) {
            throw new InvalidArgumentException('Documento deve ter 11 ou 14 posições.');
        }

        return (string) SerproEnvelope::tipo($documento);
    }
}
