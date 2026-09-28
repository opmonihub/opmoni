<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

/**
 * Quem lê o termo de autorização do escritório, e — o que é a informação
 * importante — **quem não o escreve**.
 *
 * **A escrita não existe, e isso é uma decisão de projeto, não uma
 * omissão.** A spec pedia que `admin` e `operador` pudessem "guardar ou
 * substituir um termo de autorização" e que `user` recebesse `403`; a decisão
 * registrada é que **a spec foi emendada** e a emissão é automática da
 * plataforma. A razão está em três lugares que concordam: o termo é assinado
 * com o e-CNPJ do **escritório**, que nenhum Membro tem; o design D3 diz que a
 * emissão é automática e que o escritório nunca é chamado a assinar nada; e
 * nenhuma tarefa do change pede termo submetido por Membro. Um `403` para
 * `user` e um `200` para `admin` sobre uma rota que não existe não é uma
 * garantia mais fraca — é um contrato que mente sobre o que existe.
 *
 * **Por que não há `create`/`update`/`delete` declarados aqui.** A regra da
 * `AccountCertificatePolicy`, escrita uma linha antes desta, é "método sem rota
 * é método que ninguém exercita". Uma policy com `create` devolvendo `false` e
 * nenhuma rota chamando é exatamente isso: uma linha que o próximo autor lê
 * como "aqui a escrita é permitida para quem passar", e um verbo que
 * reapareceria no `Gate` sem que ninguém perceba. O Gate nega por omissão, e a
 * ausência do verbo é o que torna essa negativa **verificável** — há um teste
 * que afirma que os quatro verbos não existem.
 *
 * **Por que `HasTenantRole`, e não um `true`.** O termo é um recurso de conta,
 * e não de plataforma: `AccountPolicy` e `SerproConnectionPolicy` dispensam o
 * trait porque cuidem de coisas que não pertencem a conta nenhuma. Aqui, sem o
 * trait, um usuário sem vínculo com a conta corrente leria o termo dela — e a
 * guarda de tenancy teria de estar em outro lugar, o que é a armadilha que o
 * próximo dia de uma rota parametrizada abriria.
 *
 * **Por que `view` também não existe.** A rota do termo é da conta corrente e
 * não endereça linha nenhuma: `GET /serpro/authorization-terms` devolve o termo
 * da conta, e a ausência do termo é um estado (`ausente`) e não um `404`. Sem
 * rota por linha, `view` com model seria um método sem exercitador, e a versão
 * que existia — `true`, sem olhar o model — era a pior das três formas: autorizava
 * a linha de qualquer conta.
 */
class SerproAuthorizationTermPolicy
{
    use HasTenantRole;

    /**
     * Qualquer Membro da conta corrente lê o termo da conta, e a **policy não
     * diz mais do que isso**: nem `admin` nem `operador` veem o documento
     * assinado, porque a resource não o devolve e esta policy não devolve nada.
     * O `user` é somente leitura na prática em toda a base, e o termo é do
     * escritório — não de uma pessoa.
     */
    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }
}
