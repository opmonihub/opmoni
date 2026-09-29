<?php

namespace App\Policies;

use App\Models\SerproMonitoring;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

/**
 * Quem lê o monitoramento e quem associa clientes a uma obrigação. Ler é de
 * qualquer Membro — o painel é de todos. Associar é escrita — o vínculo
 * `cliente × obrigação` entra na próxima execução e passa a custar cota do
 * provedor — e por isso é de `admin` e `operador`, o mesmo par que dispara
 * a execução.
 */
class SerproMonitoringPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function associate(User $user, ?SerproMonitoring $monitoring = null): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    /**
     * Abrir uma mensagem registra a ciência da intimação e abre prazo legal do
     * escritório (D19). É ato em nome do cliente, não leitura do painel, e por
     * isso é do mesmo par que escreve.
     */
    public function readMessage(User $user, ?SerproMonitoring $monitoring = null): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }
}
