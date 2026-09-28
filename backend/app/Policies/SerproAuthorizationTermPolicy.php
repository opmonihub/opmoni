<?php

namespace App\Policies;

use App\Models\SerproAuthorizationTerm;
use App\Models\User;

/**
 * Quem lê o termo de autorização do escritório, e — o que é a informação
 * importante — **quem não o escreve**.
 *
 * **A escrita não é de Membro nenhum, e a policy diz isso em vez de não
 * dizer.** A spec pede que `admin` e `operador` possam "guardar ou substituir
 * um termo de autorização" e que `user` não; o plano que implementa a spec
 * resolve o outro lado da mesma frase: a spec também diz que o escritório não
 * assina nada e que a emissão é automática, e um termo é **assinado pelo
 * e-CNPJ** — nenhum Membro tem o material para assinar. Uma rota de escrita
 * aceita por `admin` seria uma rota que o `SerproTermManager` é o único a
 * poder atender, e a policy deny-all é o que garante que ninguém a construa
 * achando que há um caso de uso.
 *
 * **A leitura é de qualquer Membro, e é a decisão que a spec e o produto
 * pedem.** O termo é do escritório, não de uma pessoa, e o `user` é
 * read-only na prática em toda a base — o mesmo tratamento que os demais
 * recursos de leitura da conta recebem.
 */
class SerproAuthorizationTermPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SerproAuthorizationTerm $term): bool
    {
        return true;
    }

    /**
     * Ninguém. O termo é assinado pelo e-CNPJ do escritório e emitido pela
     * plataforma, e nenhuma requisição de Membro produz uma assinatura
     * válida. A renovação é da agenda, e a emissão é do job que o upload do
     * e-CNPJ despacha.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SerproAuthorizationTerm $term): bool
    {
        return false;
    }

    public function delete(User $user, SerproAuthorizationTerm $term): bool
    {
        return false;
    }
}
