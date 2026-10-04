<?php

namespace App\Services;

use App\Models\SerproManualSearch;

/**
 * A régua da busca manual: 10 buscas por mês para **cada par
 * `conta × cliente × obrigação`**.
 *
 * A cota é por par e não por conta de propósito: um documento com problema
 * não pode consumir a régua dos outros, e o teto existe para a fatura do
 * gateway não depender da disciplina do operador. O mês é o calendário —
 * dia 1 a contagem volta a zero, e ninguém precisa de janela móvel.
 *
 * O que conta é o **pedido**, e não o desfecho: uma busca que o provedor
 * recusou pode ter saído do gateway mesmo assim, e a chamada falha que não
 * ocupasse cota seria um incentivo a reintentar contra a fatura.
 */
final class SerproManualSearchQuota
{
    /** O teto por par, no mês calendário. */
    public const LIMIT = 10;

    public function limit(): int
    {
        return self::LIMIT;
    }

    /**
     * As buscas já pedidas no mês corrente para o par. `withoutGlobalScope`
     * com a conta explícita: o serviço também é lido do starter em
     * transação, onde o singleton do tenant não é fonte confiável.
     */
    public function used(int $accountId, int $clientId, string $obligation): int
    {
        return SerproManualSearch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('obligation', $obligation)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }
}
