<?php

namespace App\Services\Fiscal\Exceptions;

use App\Enums\FiscalFailure;
use RuntimeException;

/**
 * A chamada não produziu resposta do serviço.
 *
 * Rejeição é estado e volta dentro do `PullResult` com a posição que a
 * resposta trouxe; a exceção fica para o que não tem resposta para ler:
 * serviço inacessível, resposta fora do contrato, credencial recusada. Por
 * isso ela carrega um `FiscalFailure` e não um código do serviço — a
 * taxonomia já é a classificação, e o que o consumidor precisa saber é se
 * adianta repetir, não qual número o fisco escolheu.
 *
 * `SerproException` é a mesma forma para o Integra Contador. Esta não a
 * estende nem a compõe: um conector fiscal lançando a exceção do SERPRO
 * acopla dois subsistemas e atribui mal a origem da falha no log.
 */
final class FiscalException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly FiscalFailure $failure,
    ) {
        parent::__construct($message);
    }
}
