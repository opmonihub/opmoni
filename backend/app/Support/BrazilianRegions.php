<?php

namespace App\Support;

final class BrazilianRegions
{
    /**
     * @var array<string, string>
     */
    private const STATE_TO_REGION = [
        'AC' => 'Norte',
        'AP' => 'Norte',
        'AM' => 'Norte',
        'PA' => 'Norte',
        'RO' => 'Norte',
        'RR' => 'Norte',
        'TO' => 'Norte',
        'AL' => 'Nordeste',
        'BA' => 'Nordeste',
        'CE' => 'Nordeste',
        'MA' => 'Nordeste',
        'PB' => 'Nordeste',
        'PE' => 'Nordeste',
        'PI' => 'Nordeste',
        'RN' => 'Nordeste',
        'SE' => 'Nordeste',
        'DF' => 'Centro-Oeste',
        'GO' => 'Centro-Oeste',
        'MT' => 'Centro-Oeste',
        'MS' => 'Centro-Oeste',
        'ES' => 'Sudeste',
        'MG' => 'Sudeste',
        'RJ' => 'Sudeste',
        'SP' => 'Sudeste',
        'PR' => 'Sul',
        'RS' => 'Sul',
        'SC' => 'Sul',
    ];

    public static function forState(?string $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        return self::STATE_TO_REGION[strtoupper(trim($state))] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['Norte', 'Nordeste', 'Centro-Oeste', 'Sudeste', 'Sul'];
    }
}
