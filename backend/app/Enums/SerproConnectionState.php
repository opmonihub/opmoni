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
 * resposta de conectividade separa os dois casos aqui.
 *
 * A divisão entre este enum e o `failed_element` de quatro valores da verificação
 * de conectividade é **semântica** e vai nos dois sentidos: `certificado` é o
 * material A1 do contratante, que este enum nem modela; `credencial` cabe em
 * `invalid` e também numa falha local em que nenhum provedor participou, que
 * aqui não tem nome; `provedor` é uma cesta de ação que junta quatro falhas,
 * enquanto `unavailable` é o sentido mais estreito de "a credencial não foi
 * julgada"; e `configuracao` cobre "não existe" e "existe incompleta", que são
 * `not_configured` e um `configured` quebrado. Nenhum dos quatro vira um caso
 * deste enum sem perder ou inventar informação.
 *
 * A **grafia** dos quatro, essa sim, é um problema aberto e não uma decisão
 * fechada. Hoje `SerproConnectivity` repete `configuracao`, `certificado`,
 * `credencial` e `provedor` em vários pontos de expressão, e um enum de mesmo
 * valor — `SerproFailedElement` — tiraria essa repetição sem custo nenhum de
 * contrato: o `->value` continuaria sendo a string publicada e a união do cliente
 * (`frontend/app/types/serpro.ts`) não mudaria. Ele não existe porque está fora do
 * escopo da tarefa que o definiria, e não porque o caminho esteja fechado.
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
