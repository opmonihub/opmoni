<?php

namespace App\Console\Commands;

use App\Enums\ClientPersonType;
use App\Enums\ClientStatus;
use App\Jobs\RefreshSerproPowersJob;
use App\Models\Account;
use App\Models\Client;
use Illuminate\Console\Command;

/**
 * A rotina diária do oráculo: uma Account habilitada de cada vez, e um job
 * por cliente pessoa jurídica ativo — nenhuma chamada aqui, só o despacho.
 * A janela de vinte horas dentro do job é o que impede a rotina de cobrar
 * duas vezes no mesmo dia, e o `account_id` é explícito porque o console
 * não tem tenant.
 */
final class RefreshSerproPowers extends Command
{
    protected $signature = 'serpro:refresh-powers';

    protected $description = 'Despacha o refresh do oráculo para cada PJ ativo de Account habilitada';

    public function handle(): int
    {
        $despachados = 0;

        // Itera as Accounts explicitamente: o escopo global não filtra nada
        // aqui, e o `enabled` é a porta — a conta desligada não entra.
        Account::query()
            ->where('settings->serpro_enabled', true)
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (&$despachados): void {
                foreach ($accounts as $account) {
                    Client::query()
                        ->withoutGlobalScope('account')
                        ->where('account_id', $account->getKey())
                        ->where('person_type', ClientPersonType::Company)
                        ->where('status', ClientStatus::Active)
                        ->orderBy('id')
                        ->chunkById(100, function ($clients) use ($account, &$despachados): void {
                            foreach ($clients as $client) {
                                RefreshSerproPowersJob::dispatch(
                                    (int) $account->getKey(),
                                    (int) $client->getKey(),
                                );
                                $despachados++;
                            }
                        });
                }
            });

        $this->info("Refreshes despachados: {$despachados}");

        return self::SUCCESS;
    }
}
