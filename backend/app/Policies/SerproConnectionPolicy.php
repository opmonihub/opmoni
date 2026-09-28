<?php

namespace App\Policies;

use App\Models\User;

/**
 * A conexão do Integra Contador é da plataforma, não de uma conta: o
 * middleware `tenant` não a alcança e nenhum papel de conta a protege. Por isso
 * a leitura é autorizada por vínculo, e não por papel — qualquer membro da
 * conta corrente precisa ver em quem a plataforma está contratada, e nada
 * além do super_admin escreve.
 */
class SerproConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $accountId = $user->current_account_id;

        return $accountId !== null && $user->accountRole((int) $accountId) !== null;
    }
}
