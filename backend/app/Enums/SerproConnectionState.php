<?php

namespace App\Enums;

/**
 * O estado da credencial da plataforma do Integra Contador, que é uma linha só
 * e não pertence a nenhuma conta.
 *
 * O eixo é o da **credencial**, não o do dado: um mesmo par configurado pode
 * estar `invalid` porque o provedor recusou o segredo ontem e voltar a
 * `configured` assim que o operador recadastrar, sem que nada tenha sido
 * sincronizado no meio. Por isso `unavailable` é separado de `invalid` — o
 * primeiro é "ninguém conseguiu responder agora", que se resolve esperando, e o
 * segundo é "responderam e recusaram", que só se resolve mexendo na credencial.
 * Tratar os dois como um só obriga o operador a refazer uma credencial boa
 * porque o serviço estava fora do ar.
 *
 * `not_configured` é o estado de quem ainda não cadastrou nada, e ele não é
 * `invalid`: uma credencial recusada se corrige, uma credencial ausente se
 * cadastra. O mesmo raciocínio que separa `configuracao` de `credencial` na
 * resposta de conectividade separa os dois casos aqui: o elemento que falhou e o
 * estado da conexão são eixos diferentes e não se convertem um no outro.
 *
 * Este enum é o vocabulário; o `failed_element` de quatro valores da
 * verificação de conectividade é outro, e continua por conta própria porque já é
 * o contrato publicado da resposta (`frontend/app/types/serpro.ts`).
 */
enum SerproConnectionState: string
{
    case NotConfigured = 'not_configured';

    /** Chave de integração e segredo gravados, e nada que prove que abrem. */
    case Configured = 'configured';

    /** O provedor respondeu e recusou: o conserto é recadastrar a credencial. */
    case Invalid = 'invalid';

    /** A verificação não pôde ser concluída; a credencial não foi julgada. */
    case Unavailable = 'unavailable';
}
