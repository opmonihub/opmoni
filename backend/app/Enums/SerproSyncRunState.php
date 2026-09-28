<?php

namespace App\Enums;

/**
 * O estado da execução de sincronização, olhada no seu próprio eixo: quantos
 * clientes foram transmitidos, de quantos foram pedidos.
 *
 * `partial` é o caso que sustenta o resto. Sem ele, uma execução em que cinco
 * clientes entraram e quatro não ficou obrigada a mentir: `completed` apagaria
 * os quatro, e `failed` contaria como erro uma execução que fez o que pôde. A
 * execução é um Pedido do operador e a resposta honesta é "cinco de nove", e é
 * por isso que `partial` existe em vez de ser lido como sucesso com ressalva.
 *
 * `queued` e `running` são o que distingue uma execução pedida de uma execução
 * em curso, e a distinção é o que permite à tela mostrar "processando" sem que
 * o operador precise adivinhar pelo relógio. `failed` é a execução que não
 * entregou nada aproveitável — a recusa da credencial é o exemplo típico.
 */
enum SerproSyncRunState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
