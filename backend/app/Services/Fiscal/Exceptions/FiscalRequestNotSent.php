<?php

namespace App\Services\Fiscal\Exceptions;

use RuntimeException;

/**
 * A consulta não chegou a sair do processo.
 *
 * Ela é a última peça da regra que o modelo da lacuna já escrevia — "a
 * tentativa é contada de consulta que **saiu**" — e que nenhuma exceção
 * genérica conseguia sustentar. Sem certificado utilizável, sem senha
 * guardada, com bytes ilegíveis no cofre ou sem espaço no disco temporário, a
 * requisição morre antes do primeiro byte: o fisco não viu nada, não respondeu
 * nada e não tem nada a dizer sobre a posição pedida.
 *
 * Cobrar a tentativa de uma recusa dessas é inventar um veredito do fisco. E são
 * três noites: um A1 inutilizável, ou um disco local cheio compartilhado por
 * toda a carteira, esgotaria a lacuna de um cliente cujo documento está lá e é
 * recuperável — a liberação dispararia, a posição pararia de segurar o cursor e
 * o documento seria abandonado para sempre.
 *
 * Por isso quem chama trata isto como `FiscalLookupDeferred`: adia a posição,
 * não gasta a tentativa, e a noite seguinte tenta de novo. A classe é nomeada
 * porque o nome é o diagnóstico: `class_basename` de um `RuntimeException` é a
 * letra `RuntimeException`, e ela não distingue certificado faltando de disco
 * cheio.
 *
 * Não é `final` porque a família tem mais de um membro: `FiscalClientStateUnknown`
 * é a mesma coisa — a requisição também não saiu — com um defeito de cadastro
 * por trás. O módulo do conector é o único lugar que lança isto.
 *
 * A mensagem é fixa e não carrega nada do cliente: quem adia precisa saber só
 * que adiou, e quem lê o log precisa do nome da classe, não do texto.
 */
class FiscalRequestNotSent extends RuntimeException
{
    public function __construct(string $message = 'A consulta não saiu do processo.')
    {
        parent::__construct($message);
    }
}
