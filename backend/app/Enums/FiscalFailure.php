<?php

namespace App\Enums;

enum FiscalFailure: string
{
    case DocumentsFound = 'documents_found';
    case NoDocuments = 'no_documents';
    case Blocked = 'blocked';
    case CursorAhead = 'cursor_ahead';
    case Unauthorized = 'unauthorized';
    case NotInterested = 'not_interested';
    /**
     * `641`: o documento existe e o fisco o nega a quem o emitiu. Não é
     * credencial nem desinteresse do destinatário, e juntá-lo ao `640` diria ao
     * operador que o cliente não é parte do documento quando ele é o emissor.
     */
    case UnavailableToIssuer = 'unavailable_to_issuer';
    case Rejected = 'rejected';
    case Upstream = 'upstream';

    /**
     * A lista de rejeições do serviço de distribuição, que é bem menor que a
     * tabela geral de NF-e: códigos do serviço de autorização (297, 539, 225)
     * não têm ramo aqui de propósito.
     *
     * `108` e `109` são indisponibilidade do serviço inteiro, não um evento do
     * CNPJ, e por isso não viram `Blocked`: retomar cedo não zera contagem
     * nenhuma. Custam um retry, não uma hora de silêncio por cliente.
     */
    public static function classify(int $httpStatus, string $cStat): self
    {
        return match ($cStat) {
            '138' => self::DocumentsFound,
            '137' => self::NoDocuments,
            '108', '109' => self::Upstream,
            '656', '678' => self::Blocked,
            '589' => self::CursorAhead,
            '593', '472', '473' => self::Unauthorized,
            '640' => self::NotInterested,
            '641' => self::UnavailableToIssuer,
            '215', '402', '404', '238', '239', '252', '214', '236', '217', '632', '653', '654', '999' => self::Rejected,
            default => $httpStatus === 0 || $httpStatus >= 500 ? self::Upstream : self::Rejected,
        };
    }

    /**
     * Eixo independente do bloqueio: o `656` bloqueia por uma hora e ainda
     * assim vale repetir depois que a janela passa.
     */
    public function retryable(): bool
    {
        return match ($this) {
            self::Upstream, self::Blocked => true,
            default => false,
        };
    }

    /**
     * Retomar antes de completar uma hora zera a contagem do fisco e a
     * reinicia, então a única saída é parada absoluta.
     */
    public function blocksForAnHour(): bool
    {
        return match ($this) {
            self::Blocked, self::NoDocuments => true,
            default => false,
        };
    }
}
