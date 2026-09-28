<?php

namespace App\Services\Fiscal\Contracts;

use App\Enums\FiscalFailure;
use Carbon\CarbonImmutable;

/**
 * O resultado de um lote de distribuição: os documentos, as entradas que não
 * viraram documento, e a posição a persistir depois deles.
 *
 * A ordem é deliberada e é o que segura a captura contra interrupção: o
 * consumidor grava `documents` primeiro e só depois adota `lastNsu` — e só
 * quando `mayAdoptPosition` autoriza.
 *
 * Rejeição é estado, não exceção. Uma rejeição que bloqueia — `137` de nenhum
 * documento localizado, `656` de consumo indevido — **volta como resultado**, com
 * `blockedUntil` preenchido e a posição que a resposta traz, porque o serviço
 * respondeu: a resposta é "pare uma hora". A exceção (`FiscalException`, criada
 * com o conector) é para a chamada que não produz resposta alguma: indisponibilidade
 * do serviço, credencial recusada, CNPJ sem correspondência, certificado
 * inutilizável, posição à frente. Os dois canais não são redundantes — um diz "a
 * resposta é esperar", o outro diz "não houve resposta".
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
     *                                              ser consultado de novo, porque
     *                                              o serviço mandou esperar
     * @param  bool  $mayAdoptPosition  se o consumidor pode gravar `lastNsu` como
     *                                  a nova posição do cliente. O valor é sempre o que a
     *                                  resposta devolveu; o que muda é se a resposta o
     *                                  autorizou:
     *
     *                        - **Lote que virou documento inteiro, sem entrada
     *                          recusada** — sim. É a posição até onde o cliente
     *                          leu, e a gravação dos documentos vem antes dela.
     *                        - **Alguma entrada recusada** — não. Uma posição que
     *                          não virou documento é um buraco, e adotar a
     *                          posição depois dele perde o buraco em silêncio na
     *                          consulta seguinte, que nunca mais vai pedi-la.
     *                        - **Nenhum documento localizado** — não. A posição
     *                          armazenada fica intacta: o serviço não entregou
     *                          nada, e o que ele devolve nesse caso é o eco da
     *                          posição pedida, não uma posição nova. Gravar
     *                          sobrescreveria o cursor com o valor anterior.
     *                        - **Rejeição por consumo indevido** — sim, e é a
     *                          razão de a posição vir mesmo com a rejeição: é a
     *                          posição correta, entregue dentro do corpo da
     *                          própria rejeição, e é a alavanca de recuperação que
     *                          o fisco oferece. Perdê-la seria voltar ao começo.
     *
     *                        O conector é quem sabe qual dos quatro casos
     *                        aconteceu, porque só ele viu o código que voltou.
     * @param  list<FailedEntry>  $failures  as entradas do lote que não viraram
     *                                       documento, na ordem em que chegaram
     * @param  FiscalFailure|null  $failure  por que a resposta foi uma rejeição
     *                                       que parou a captura, quando foi. `null`
     *                                       é o caso normal — o serviço respondeu
     *                                       e entregou (ou não tinha) documentos, e
     *                                       nenhuma pausa foi pedida.
     *
     *                                        O campo existe porque duas paradas de
     *                                        uma hora são indistinguíveis pelo
     *                                        relógio: `137` é o fisco sem nada
     *                                        novo, rotina que se repete a cada
     *                                        consulta, e `656` é o CNPJ que
     *                                        consultou demais, que é problema do
     *                                        cliente. `blockedUntil` é igual nos
     *                                        dois casos, então a distinção que
     *                                        sobra é este rótulo.
     */
    public function __construct(
        public array $documents,
        public int $lastNsu,
        public ?int $maxNsu,
        public bool $more,
        public ?CarbonImmutable $blockedUntil,
        public bool $mayAdoptPosition,
        public array $failures = [],
        public ?FiscalFailure $failure = null,
    ) {}
}
