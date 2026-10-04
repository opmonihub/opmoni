<?php

namespace App\Enums;

/**
 * O recorte que o operador pediu na busca manual.
 *
 * `full` é a leitura inteira que o serviço responde. `slip_status` é a
 * busca que a tela chama de "só status pago-não-pago" — e como nenhum
 * serviço habilitado do provedor documenta filtro de recorte no pedido, a
 * chamada sai completa dos dois jeitos: o modo é metadado do pedido, registrado
 * para a auditoria dizer o que o operador quis, e não um parâmetro que o
 * gateway receba.
 */
enum SerproManualSearchMode: string
{
    case Full = 'full';
    case SlipStatus = 'slip_status';
}
