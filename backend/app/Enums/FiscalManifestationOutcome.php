<?php

namespace App\Enums;

enum FiscalManifestationOutcome: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case AlreadyManifested = 'already_manifested';
    /**
     * A requisição não pode sair por defeito do nosso lado — documento sem
     * autor, chave que não fecha o dígito — e nenhum retry a conserta.
     */
    case NotSendable = 'not_sendable';
    /**
     * A ciência da emissão tem prazo legal de 90 dias; vencido, enviar é uma
     * rejeição certa que custa tentativa, então o veredito é registrado sem
     * envio.
     */
    case DeadlineMissed = 'deadline_missed';

    /**
     * Veredito é o estado que o conector grava quando o fisco (ou a nossa
     * impossibilidade de pedir) já respondeu: `sent`, `already_manifested`,
     * `deadline_missed` e `not_sendable`. Uma re-entrega do resumo não o
     * refaz — enviar de novo provocaria o `573` e rebaixaria o registro —
     * então o `registrarPedido` o preserva e o dispatcher não enfileira
     * outro job.
     *
     * `Failed` fica de fora de propósito: nenhum caminho o grava hoje, e um
     * registro que parasse nele seria reenviado por uma re-entrega — que é
     * a recuperação pretendida, não uso indevido.
     */
    public function isVerdict(): bool
    {
        return match ($this) {
            self::Sent,
            self::AlreadyManifested,
            self::DeadlineMissed,
            self::NotSendable => true,
            default => false,
        };
    }
}
