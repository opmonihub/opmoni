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
 * Gravar é de `admin` e `operador`, pelo mesmo motivo de `Client` e das
 * demais escritas da conta: o papel `user` é somente leitura na prática e não aparece em
 * nenhuma policy de escrita do produto. E a mão que grava aqui grava um
 * segredo — o e-CNPJ que assina o termo em nome da conta —, o que faz de
 * "quem pode" uma pergunta de consequência, não de conveniência de tela.
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
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    public function delete(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }
}
