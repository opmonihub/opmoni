<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\FiscalDocument;
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

    /**
     * O mesmo direito de leitura, agora sobre uma linha só.
     *
     * A restrição por conta não é comparada aqui à mão: quem decide é o
     * binding restrito de `BelongsToAccount`, e o que sobra para a policy é
     * dizer que ler uma linha é leitura de carteira. Uma comparação escrita
     * aqui seria uma segunda implementação da mesma regra — e a que
     * esqueceria o cliente removido ou o modo suporte.
     */
    public function view(User $user, FiscalDocument $document): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Disparar captura é a única escrita do módulo fiscal, e ela custa uma
     * consulta ao CNPJ do cliente — que o fisco conta e que o consumo indevido
     * zera. Por isso `admin` e `operador` apenas, como em todo o resto da
     * carteira; `user` lê e não escreve.
     */
    public function capture(User $user, Client $client): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }
}
