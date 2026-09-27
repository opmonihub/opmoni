<?php

namespace App\Services\Fiscal\Contracts;

use Carbon\CarbonImmutable;

/**
 * O resultado de um lote de distribuição: os documentos e a posição a
 * persistir depois deles.
 *
 * A ordem é deliberada e é o que segura a captura contra interrupção: o
 * consumidor grava `documents` primeiro e só depois adota `lastNsu`.
 */
final readonly class PullResult
{
    /**
     * @param  list<PulledDocument>  $documents  na ordem em que chegaram: a gravação por etapa depende dessa ordem
     * @param  int  $lastNsu  a posição a persistir depois deste lote — sempre o
     *                        valor devolvido pela resposta, nunca o valor local
     *                        somado de um
     * @param  int|null  $maxNsu  a maior posição que o serviço conhece, quando a
     *                            resposta a traz; nulo quando a resposta omite, o
     *                            que não é a mesma coisa que fila vazia
     * @param  bool  $more  o serviço ainda tem posições depois desta, para quem
     *                      quiser continuar sem esperar o próximo agendamento
     * @param  CarbonImmutable|null  $blockedUntil  até quando o cliente não pode
     *                                              ser consultado de novo, quando
     *                                              o conector o informa
     */
    public function __construct(
        public array $documents,
        public int $lastNsu,
        public ?int $maxNsu,
        public bool $more,
        public ?CarbonImmutable $blockedUntil,
    ) {}
}
