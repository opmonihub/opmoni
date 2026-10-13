<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

/**
 * Quem dispara e quem lê o Rastreio na folha PGDAS.
 *
 * Ler é de qualquer Membro — a linha do monitoramento é de todos, e o
 * status do envio é parte dela. Disparar é de `admin` e `operador`: o
 * envio sai pelo número do escritório e em nome dele, e o par é o mesmo
 * que associa clientes, baixa declarações e abre ciência de intimação.
 * `user` lê Rastreio e nunca dispara.
 */
class WhatsAppDispatchPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    /**
     * O verbo de criação do Disparo — o POST que enfileira o envio.
     */
    public function create(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }
}
