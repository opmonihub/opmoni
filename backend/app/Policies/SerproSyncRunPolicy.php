<?php

namespace App\Policies;

use App\Models\SerproSyncRun;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

/**
 * Quem pode disparar e quem pode olhar. Escrever é de `admin` e `operador`:
 * cada execução gasta a cota do escritório no provedor, e a decisão não é
 * de quem só lê a carteira. Ler é de qualquer Membro — a tela de todos
 * mostra a última sincronização — e o mesmo vale para o re-sync, que é
 * escrita: gera uma execução nova.
 */
class SerproSyncRunPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function view(User $user, SerproSyncRun $run): bool
    {
        return $this->tenantRole($user) !== null && $this->isTenantModel($run->account_id);
    }

    public function create(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    public function resync(User $user, SerproSyncRun $run): bool
    {
        return $this->create($user) && $this->isTenantModel($run->account_id);
    }
}
