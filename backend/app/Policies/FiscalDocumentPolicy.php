<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

class FiscalDocumentPolicy
{
    use HasTenantRole;

    /**
     * Ler o que a conta capturou é leitura de carteira, e carteira é visível para
     * qualquer membro: `user` só não escreve, e fechar a leitura para quem só
     * pode ler deixaria o painel fiscal como a única tela do produto que o
     * membro read-only não alcança.
     */
    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }
}
