<?php

namespace App\Services\Fiscal\Exceptions;

use RuntimeException;

/**
 * A consulta pontual não saiu porque o teto horário do CNPJ acabou.
 *
 * Não é `FiscalException` e não é `RuntimeException` genérica porque quem
 * chama precisa tratar os três de jeitos diferentes: `FiscalException` é "o
 * serviço não respondeu" e vira retentativa, `RuntimeException` é defeito
 * nosso, e isto aqui é uma decisão nossa de não consultar agora. A
 * reconciliação adia a posição e **não gasta a tentativa** — gastar uma
 * tentativa de uma consulta que nunca saiu empurraria a posição para a hora
 * seguinte sem que nada tenha sido tentado.
 *
 * A mensagem é fixa e não carrega nada do fisco: quem adia precisa saber só
 * que adiou.
 */
final class FiscalLookupDeferred extends RuntimeException
{
    public function __construct(string $message = 'Limite horário de consultas pontuais atingido.')
    {
        parent::__construct($message);
    }
}
