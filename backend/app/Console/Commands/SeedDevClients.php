<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Client;
use App\Models\Plan;
use App\Tenant\CurrentTenant;
use Database\Seeders\DevClientPortfolioSeeder;
use Illuminate\Console\Command;

class SeedDevClients extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dev:seed-clients
        {--account= : Conta destino (id). Padrão: primeira conta.}
        {--count=200 : Quantidade de clientes.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera uma carteira sintética de desenvolvimento (clientes + cert A1 + e-CAC + tags) numa conta existente.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountOption = $this->option('account');
        $count = max(1, (int) $this->option('count'));

        $tenant = $accountOption !== null && $accountOption !== ''
            ? Account::query()->findOrFail((int) $accountOption)
            : Account::query()->orderBy('id')->first();

        if ($tenant === null) {
            $this->error('Nenhuma conta encontrada. Crie uma conta primeiro (onboarding ou register).');

            return self::FAILURE;
        }

        return resolve(CurrentTenant::class)->run((int) $tenant->getKey(), function () use ($tenant, $count) {
            $seeder = new DevClientPortfolioSeeder;
            $seeder->setContainer($this->laravel);
            $seeder->setCommand($this);
            $seeder->run($tenant->getKey(), $count);

            $this->table(
                ['Métrica', 'Valor'],
                array_merge(
                    [['Clientes (seed)', $count], ['Conta', "{$tenant->getKey()} ({$tenant->name})"]],
                    collect(['missing' => 'Sem cadastro', 'valid' => 'Válido', 'expiring' => 'A vencer', 'expired' => 'Vencido'])
                        ->map(fn (string $label, string $state): array => [
                            "Prazo {$label} (carteira)",
                            Client::query()->withDeadlineStatus($state)->count(),
                        ])->values()->all(),
                    [[
                        'Plano',
                        Plan::query()->where('slug', 'empresarial')->exists() ? 'empresarial (ilimitado)' : 'n/d',
                    ]],
                )
            );

            return self::SUCCESS;
        });
    }
}
