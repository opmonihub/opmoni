<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;

/**
 * O evento de manifestação não pôde ser assinado, e por isso não saiu.
 *
 * É da família `FiscalRequestNotSent` porque a consequência é a mesma: o fisco
 * não viu nada e não tem veredito a dar. Um A1 que não abre, um evento sem
 * `infEvento` identificável ou uma assinatura que não confere com a própria
 * chave pública morrem aqui, antes do primeiro byte — e quem enfileira trata
 * como requisição que não saiu, não como rejeição do Ambiente Nacional.
 *
 * A mensagem é fixa por motivo: não leva senha, PEM, XML do evento nem chave
 * de acesso. Quem lê o log precisa do nome da classe e da frase; o resto é
 * identificador do registro de manifestação, que mora em quem chamou.
 */
final class EventSignatureFailed extends FiscalRequestNotSent
{
    public static function becauseTheCertificateDidNotOpen(): self
    {
        return new self('O certificado A1 do cliente não pôde ser aberto para assinar o evento.');
    }

    public static function becauseTheEventIsNotSignable(): self
    {
        return new self('O evento não traz um infEvento com Id para assinar.');
    }

    public static function becauseSigningFailed(): self
    {
        return new self('A assinatura do evento não pôde ser produzida.');
    }

    public static function becauseTheSignatureDoesNotVerify(): self
    {
        return new self('A assinatura produzida não confere com o certificado do cliente.');
    }
}
