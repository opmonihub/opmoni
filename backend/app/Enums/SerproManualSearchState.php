<?php

namespace App\Enums;

/**
 * O ciclo de vida de uma busca manual sob demanda.
 *
 * `queued` é o que o POST acaba de criar e a fila ainda não pegou — é o
 * estado que o painel mostra como "Processando". `running` é a busca em
 * curso, inclusive nas reentregas de throttle. `completed` e `failed` são
 * terminais, e `reason` é o que diferencia as falhas que o operador pode
 * consertar das que só o provedor pode.
 */
enum SerproManualSearchState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
