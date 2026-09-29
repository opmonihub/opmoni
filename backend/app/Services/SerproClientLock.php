<?php

namespace App\Services;

use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * A serialização por contribuinte.
 *
 * O PGMEI exige uma requisição por vez por CNPJ, e duas entregas do mesmo
 * job — ou dois jobs sobre o mesmo cliente numa execução duplicada — sem a
 * trava mandariam chamadas em paralelo para o mesmo documento. A chave leva
 * `account_id` junto porque `tax_id` não é prova de conta, e o lease é
 * maior que o `timeout` do job filho: um lock que vencesse antes do prazo
 * deixaria a segunda entrega entrar com a primeira ainda no ar.
 */
final class SerproClientLock
{
    /** Segundos de lease: o timeout do job é 75 e a folga é proposital. */
    private const LEASE_SECONDS = 90;

    public function acquire(int $accountId, string $taxId): ?Lock
    {
        $lock = Cache::lock("serpro:client:{$accountId}:{$taxId}", self::LEASE_SECONDS);

        return $lock->get() ? $lock : null;
    }
}
