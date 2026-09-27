<?php

namespace App\Enums;

enum FiscalModel: string
{
    case Nfe = 'nfe';
    case Nfce = 'nfce';
    case Cte = 'cte';
    case Nfse = 'nfse';

    /**
     * O código de modelo do documento fiscal (campo `mod` da chave de acesso).
     */
    public static function fromDocumentModel(string $model): ?self
    {
        return match ($model) {
            '55' => self::Nfe,
            '65' => self::Nfce,
            '57' => self::Cte,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nfe => 'NF-e',
            self::Nfce => 'NFC-e',
            self::Cte => 'CT-e',
            self::Nfse => 'NFS-e',
        };
    }
}
