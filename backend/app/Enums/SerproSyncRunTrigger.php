<?php

namespace App\Enums;

/**
 * O que disparou a execução.
 *
 * `manual` é o botão do operador, e é o único que a cota mensal conta: o
 * contratante paga por chamada, e o teto de buscas do mês protege a fatura.
 * `scheduled` é a execução única do mês que a própria plataforma agenda —
 * nasce do console, sem operador, e não pode ser recusada por uma cota que
 * o operador consumiu antes.
 */
enum SerproSyncRunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
}
