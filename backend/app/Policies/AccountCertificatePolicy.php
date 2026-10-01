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
 * Gravar é só do `is_super_admin`, e não de papel da Account. O que se grava
 * aqui é o e-CNPJ que assina o termo de autorização **em nome da plataforma**:
 * nenhum Membro da conta assina nada, e por isso o `admin` e o `operador` —
 * que escrevem em todo o resto da conta — não escrevem nesta. A escrita na
 * conta corrente é a escrita do modo suporte, e ela já sai auditada.
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
        return $user->isSuperAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
