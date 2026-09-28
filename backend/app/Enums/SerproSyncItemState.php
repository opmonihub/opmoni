<?php

namespace App\Enums;

/**
 * O estado de um cliente dentro de uma execução, e em português porque é o
 * relatório que o operador lê na carteira.
 *
 * Os cinco casos separam o que o relatório não pode misturar: `sincronizado` é o
 * que o cliente entregou, `ignorado` é o que foi pulado de propósito — um cliente
 * sem procuração não é uma falha, é uma decisão —, `nao_processado` é o que ainda
 * não aconteceu, e `falhou` é o que aconteceu e deu errado. Um único "não
 * sincronizou" não separa nada disso: ele apagaria do relatório justamente o que a
 * execução entregou, e contaria como erro uma decisão.
 *
 * Juntar `falhou` com `indeterminado` mentiria sobre a única pergunta que a
 * sincronização faz ao provedor: "isto foi aplicado?".
 *
 * `indeterminado` é a única resposta a essa pergunta que o provedor não deu: o
 * tempo limite venceu com o identificador de resposta em mãos, e o item pode
 * ter sido aplicado. Ele é contado **fora** de `failed` por isso — do ponto de
 * vista do fisco ele existe, e quem reenviar o pedido pode duplicar o efeito. Não
 * é o mesmo que `SerproFailure::NotSent`, que é a falha local anterior a
 * qualquer requisição e por isso é contada como falha: a pergunta nem chegou a
 * existir.
 */
enum SerproSyncItemState: string
{
    case Synchronized = 'sincronizado';
    case Skipped = 'ignorado';
    case Failed = 'falhou';
    case Indeterminate = 'indeterminado';
    case NotProcessed = 'nao_processado';
}
