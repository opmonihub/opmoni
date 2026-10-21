<?php

namespace App\Erp\Fiscal;

use InvalidArgumentException;

final class Nfce65DocumentBuilder extends Nfe55DocumentBuilder
{
    protected const MODEL = '65';

    public function validateSource(array $source): void
    {
        parent::validateSource($source);
        if (($source['identity']['operation_type'] ?? null) !== 1 || ($source['identity']['final_consumer'] ?? 1) !== 1) {
            throw new InvalidArgumentException('NFC-e exige operação interna de venda ao consumidor final.');
        }
    }
}
