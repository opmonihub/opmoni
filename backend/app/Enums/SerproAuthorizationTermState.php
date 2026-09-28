<?php

namespace App\Enums;

/**
 * O estado do termo de autorização do escritório, e a ordem dos casos é o ciclo
 * de vida dele.
 *
 * `validado` e `autenticado` são estados diferentes e a diferença entre eles é
 * o que impede o erro caro: o primeiro é o documento que o provedor aceitou como
 * válido, o segundo é o que já rendeu token de autorização. Trocar um pelo outro
 * apresentaria ao escritório um termo "autorizado" que nenhuma chamada ao gateway
 * aceitou, e a falha apareceria no meio de uma sincronização, longe da tela que a
 * causou.
 *
 * `ausente` e `recusado` são os dois que o provedor não produz: o primeiro é o
 * que o escritório ainda não assinou, o segundo é o que ele não consegue assinar
 * sem corrigir algo. Os dois levam a nenhuma chamada, por motivos opostos — um
 * é trabalho pendente do escritório e o outro é um defeito que não se resolve
 * tentando de novo.
 *
 * Este é o termo do **escritório**, não do cliente: um termo serve a carteira
 * inteira e o que individualiza o acesso é a procuração e-CAC, em
 * `SerproPowerOfAttorneyState`.
 */
enum SerproAuthorizationTermState: string
{
    case Ausente = 'ausente';
    case Pendente = 'pendente';
    case Validado = 'validado';
    case Autenticado = 'autenticado';
    case Vencido = 'vencido';
    case Recusado = 'recusado';
}
