<?php

namespace App\Enums;

enum FiscalSource: string
{
    case NfeDistribuicao = 'nfe_distribuicao';
    case CteDistribuicao = 'cte_distribuicao';
    case NfseAdn = 'nfse_adn';

    public function label(): string
    {
        return match ($this) {
            self::NfeDistribuicao => 'Distribuição NF-e',
            self::CteDistribuicao => 'Distribuição CT-e',
            self::NfseAdn => 'ADN NFS-e',
        };
    }
}
