<?php

namespace App\Enums;

/**
 * O estado da procuração e-CAC no serviço do SERPRO, que é o que decide se o
 * cliente é elegível a um pedido.
 *
 * É o estado da procuração, e não o do dado: um cliente com `established` pode
 * não ter nada sincronizado ainda, e um cliente com dado sincronizado de ontem
 * tem `expired` hoje — a procuração vence, e o que está guardado envelhece com
 * ela. Confundir os dois eixos apresentaria como "em dia" um cliente que não
 * pode mais ser consultado.
 *
 * `rejected` é o que o provedor respondeu e `expired` é o que o calendário
 * consomme: os dois levam a não consultar o cliente, mas por consertos
 * diferentes, e o operador precisa saber qual dos dois é. Os valores são em
 * inglês porque este é o vocabulário do serviço do provedor, e não o que o
 * operador lê na carteira.
 */
enum SerproPowerOfAttorneyState: string
{
    case Pending = 'pending';
    case Established = 'established';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
