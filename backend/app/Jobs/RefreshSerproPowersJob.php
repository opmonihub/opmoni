<?php

namespace App\Jobs;

use App\Enums\ClientPersonType;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproClientAuthorization;
use App\Services\SerproAccountEnablement;
use App\Services\SerproClientLock;
use App\Services\SerproPowerOracle;
use App\Services\SerproTermManager;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O oráculo fora da execução: um job por cliente, com `account_id` explícito
 * e o lock do contribuinte, para não correr junto com o
 * `SyncSerproClientJob` do mesmo cliente nem depender do `CurrentTenant`
 * que o worker herdou.
 *
 * A consulta é cobrada, e a rotina diária só despacha quem **nunca** foi
 * consultado — o comando não manda mais quem já tem linha gravada. A janela
 * de vinte horas abaixo segue como guarda dos despachos sem `force` que
 * possam alcançar um cliente já consultado, e não é mais a política de
 * revalidação: a vigência local (`expires_on`, estado `established`) é que
 * decide em memória, e a revogação chega pela recusa 022.
 *
 * O `force` é do gatilho de troca de documento — o CNPJ novo pode ter
 * outorga que o antigo não tinha, e a resposta anterior media outro
 * contribuinte.
 */
final class RefreshSerproPowersJob implements ShouldQueue
{
    use Queueable;

    /** Abaixo do `retry_after` de 90s do redis, como o SyncSerproClientJob. */
    public int $timeout = 75;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(
        public int $accountId,
        public int $clientId,
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        $tenant = resolve(CurrentTenant::class);
        $previous = $tenant->accountId;
        $tenant->accountId = $this->accountId;

        try {
            $this->work();
        } finally {
            $tenant->accountId = $previous;
        }
    }

    private function work(): void
    {
        // A conta é relida aqui, e não no disparo: o flag pode ter sido
        // desligado depois que o job nasceu, e desligar é contenção.
        if (! resolve(SerproAccountEnablement::class)->enabled($this->accountId)) {
            return;
        }

        $client = Client::query()->where('account_id', $this->accountId)->find($this->clientId);

        if ($client === null || $client->person_type !== ClientPersonType::Company || $client->tax_id === null) {
            return;
        }

        if (resolve(SerproTermManager::class)->validToken($this->accountId) === null) {
            return;
        }

        if (AccountCertificate::currentFor($this->accountId) === null) {
            return;
        }

        // Guarda de janela, e não política de revalidação: a rotina diária
        // já não despacha quem tem linha, e o `force` da troca de documento
        // pula ela porque a resposta era de outro CNPJ.
        if (! $this->force && $this->verificadoRecentemente()) {
            return;
        }

        $lock = resolve(SerproClientLock::class)->acquire($this->accountId, (string) $client->tax_id);

        if ($lock === null) {
            $this->release(15);

            return;
        }

        try {
            resolve(SerproPowerOracle::class)->refresh($this->accountId, $this->clientId);
        } finally {
            $lock->release();
        }
    }

    /**
     * O cliente foi consultado nas últimas vinte horas? O `verified_at` mais
     * recente das famílias é a medida, e nenhuma linha é "nunca". Quem chegou
     * aqui sem `force` com linha recente é despacho duplicado: a resposta já
     * está gravada e a consulta não sai de novo.
     */
    private function verificadoRecentemente(): bool
    {
        $ultima = SerproClientAuthorization::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->where('client_id', $this->clientId)
            ->max('verified_at');

        if ($ultima === null) {
            return false;
        }

        return CarbonImmutable::parse($ultima)->gte(now()->subHours(20));
    }
}
