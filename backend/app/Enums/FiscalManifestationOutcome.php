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
}
