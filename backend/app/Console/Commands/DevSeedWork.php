<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Department;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\Task;
use App\Tenant\CurrentTenant;
use Database\Seeders\DevWorkSeeder;
use Illuminate\Console\Command;

class DevSeedWork extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dev:seed-work
        {--account= : Conta destino (id). Padrão: primeira conta.}
        {--month= : Mês de referência (Y-m). Padrão: mês atual.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera dados de desenvolvimento do Work (departamentos + modelos + processos/tasks do mês) numa conta existente.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountOption = $this->option('account');
        $month = $this->option('month');

        if ($month !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)) {
            $this->error('Mês inválido. Use o formato Y-m (ex.: 2026-09).');

            return self::FAILURE;
        }

        $tenant = $accountOption !== null && $accountOption !== ''
            ? Account::query()->findOrFail((int) $accountOption)
            : Account::query()->orderBy('id')->first();

        if ($tenant === null) {
            $this->error('Nenhuma conta encontrada. Crie uma conta primeiro (onboarding ou register).');

            return self::FAILURE;
        }

        return resolve(CurrentTenant::class)->run((int) $tenant->getKey(), function () use ($tenant, $month) {
            $seeder = new DevWorkSeeder;
            $seeder->setContainer($this->laravel);
            $seeder->setCommand($this);
            $seeder->run($tenant->getKey(), $month);

            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Conta', "{$tenant->getKey()} ({$tenant->name})"],
                    ['Departamentos', Department::query()->count()],
                    ['Modelos Dev', ProcessTemplate::query()->where('name', 'like', 'Modelo Dev %')->count()],
                    ['Processos (conta)', Process::query()->count()],
                    ['Tasks (conta)', Task::query()->count()],
                ]
            );

            return self::SUCCESS;
        });
    }
}
