<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

/**
 * Quem toca no Canal WhatsApp do escritório.
 *
 * Ler é de qualquer Membro: o estado do canal é o mesmo dado que a tela de
 * pareamento e a coluna de Disparo publicam, e escondê-lo de quem lê a conta
 * seria um `403` que não protege nada.
 *
 * Parear e desconectar são do `admin` da Account corrente (e do
 * `is_super_admin`, que atua como `admin` no tenant — inclusive em modo
 * suporte, com auditoria). O número que se pareia é o telefone do escritório:
 * ligar um número pessoal à conta não é decisão de quem só opera a rotina.
 *
 * **Os verbos de escrita recebem a classe, e não a linha.** O canal é um por
 * conta e as rotas não endereçam linha nenhuma: o pareamento cria ou retoma o
 * canal da conta corrente e a desconexão apaga a sessão da conta corrente.
 * Método sem rota é método que ninguém exercita — `update`, `restore` e
 * `forceDelete` não existem aqui porque não há rota que os chame.
 */
class WhatsAppChannelPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    /**
     * Iniciar (ou retomar) o pareamento — o verbo de criação do canal.
     */
    public function create(User $user): bool
    {
        return $this->canManageChannel($user);
    }

    /**
     * Desconectar a sessão — o verbo de remoção do canal.
     */
    public function delete(User $user): bool
    {
        return $this->canManageChannel($user);
    }

    private function canManageChannel(User $user): bool
    {
        return $this->tenantRole($user) === 'admin';
    }
}
