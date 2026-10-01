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
 * A janela de vinte horas é o que torna a rotina diária barata: quem foi
 * verificado há menos tempo já tem a resposta, e o refresh não cobra de
 * novo. O `force` é do gatilho de troca de documento — o CNPJ novo pode ter
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

        // A janela de vinte horas: quem já foi verificado recentemente tem a
        // resposta, e a rotina não paga de novo o que o provedor acabou de
        // dizer. O `force` da troca de documento pula ela porque a resposta
        // era de outro CNPJ.
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
     * recente das famílias é a medida, e nenhuma linha é "nunca".
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
