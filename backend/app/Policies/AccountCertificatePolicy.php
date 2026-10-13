<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

/**
 * Quem toca no e-CNPJ do escritório.
 *
 * Ler é de qualquer Membro: qual documento está gravado é o mesmo dado que a
 * tela de integração do SERPRO publica, e escondê-lo de quem lê a conta seria
 * um `403` que não protege nada — o `admin` da conta lê a mesma tela.
 *
 * Gravar é do `admin` da Account corrente (e do `is_super_admin`, que atua como
 * `admin` no tenant). O `operador` e o `user` leem metadados, mas não entregam
 * nem removem o e-CNPJ do escritório.
 *
 * **Os dois verbos de escrita recebem a classe, e não a linha.** O e-CNPJ do
 * escritório é um por conta e as rotas não endereçam linha nenhuma: o upload
 * substitui o que está valendo e a remoção apaga o que está valendo, e o
 * endereço de um é o endereço do outro. Por isso `update`/`delete` com modelo não
 * existem aqui — método sem rota é método que ninguém exercita.
 */
class AccountCertificatePolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->canManageOfficeCertificate($user);
    }

    public function delete(User $user): bool
    {
        return $this->canManageOfficeCertificate($user);
    }

    private function canManageOfficeCertificate(User $user): bool
    {
        return $this->tenantRole($user) === 'admin';
    }
}
