<?php

namespace App\Enums;

enum FiscalModel: string
{
    case Nfe = 'nfe';
    case Nfce = 'nfce';
    case Cte = 'cte';
    case CteOs = 'cte_os';
    case Gtve = 'gtve';
    case Nfse = 'nfse';

    /**
     * O código de modelo do documento fiscal (campo `mod` da chave de acesso).
     *
     * Devolve `null` para o que não está no catálogo, e é essa recusa — e não
     * um valor de reserva — que impede que uma chave de modelo desconhecida seja
     * gravada sob a identidade de algum modelo conhecido.
     */
    public static function fromDocumentModel(string $model): ?self
    {
        return match ($model) {
            '55' => self::Nfe,
            '65' => self::Nfce,
            '57' => self::Cte,
            '67' => self::CteOs,
            '64' => self::Gtve,
            default => null,
        };
    }

    /**
     * Os modelos que a mesma família de distribuição entrega.
     *
     * A família é o que o **conector** representa: `FiscalModel::Cte` é o
     * conector de CT-e, e ele entrega o CT-e regular, o simplificado, o OS e o
     * GTV-e, todos pelo mesmo serviço e pelo mesmo lote. Chamar `extract()` com
     * `Cte` diz qual conector perguntou, não qual modelo o documento é — e é a
     * chave de acesso do próprio documento que diz qual.
     *
     * A família da NF-e é unitária de propósito: `Nfe` e `Nfce` têm serviços de
     * distribuição próprios, e aceitar um pelo outro seria afrouxar a guarda
     * exatamente onde ela não tem por que ser afrouxada.
     *
     * @return list<self>
     */
    public function family(): array
    {
        return match ($this) {
            self::Cte, self::CteOs, self::Gtve => [self::Cte, self::CteOs, self::Gtve],
            self::Nfe => [self::Nfe],
            self::Nfce => [self::Nfce],
            self::Nfse => [self::Nfse],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nfe => 'NF-e',
            self::Nfce => 'NFC-e',
            self::Cte => 'CT-e',
            self::CteOs => 'CT-e OS',
            self::Gtve => 'GTV-e',
            self::Nfse => 'NFS-e',
        };
    }
}
