<?php

namespace App\Policies;

use App\Models\ClientDispatchContact;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

/**
 * Quem cadastra e quem lê os Contatos de envio do cliente.
 *
 * Ler é de qualquer Membro — o modal Enviar guias e o cadastro na folha
 * servem a mesma lista para `admin`, `operador` e `user`. Escrever é de
 * `admin` e `operador`: o destino do envio sai em nome do escritório, e
 * `user` lê Rastreio sem cadastrar. O `is_super_admin` passa por
 * `tenantRole` como `admin`, inclusive em modo suporte — a auditoria é
 * do controller, não da policy.
 *
 * O cliente dono do contato resolve pelo binding escopado do tenant
 * antes da policy rodar: id de cliente alheio é 404 na rota, nunca 403.
 */
class ClientDispatchContactPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ClientDispatchContact $contact): bool
    {
        return $this->isTenantModel($contact->account_id) && $this->canManage($user);
    }

    public function delete(User $user, ClientDispatchContact $contact): bool
    {
        return $this->isTenantModel($contact->account_id) && $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }
}
