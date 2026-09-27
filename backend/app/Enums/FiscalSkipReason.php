<?php

namespace App\Enums;

/**
 * Por que uma execução de captura não consultou o serviço.
 *
 * Os casos não são sinônimos, e a distinção que mais importa é a de cima: um
 * `Blocked` é o fisco mandando este cliente parar por uma hora depois de consumo
 * indevido, é estado do cliente e dura a hora; um `Locked` é outro trabalho no ar
 * para o mesmo cliente e a mesma fonte, é um evento do agendamento e some com ele.
 * Dizer `blocked` para os dois descreve ao operador um estado que o cliente não
 * tem — e a serialização existe porque consultas paralelas ao mesmo CNPJ são
 * classificadas como uso indevido pela NT, o que tornaria o erro autoalimentado.
 */
enum FiscalSkipReason: string
{
    case Blocked = 'blocked';
    case Locked = 'locked';
    case NoCertificate = 'no_certificate';
    case Interrupted = 'interrupted';
}
