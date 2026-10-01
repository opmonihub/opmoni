<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Department;
use App\Models\ProcessTemplate;
use App\Models\Tag;
use App\Services\ProcessGenerationService;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Dados de desenvolvimento para o Work (departamentos + modelo + geração):
 * idempotente por conta via marcadores `seed-dev` (tag) e modelos `Modelo Dev *`.
 *
 * Uso (requer uma conta existente):
 *   php artisan dev:seed-work --account=6
 *   php artisan dev:seed-work --account=6 --month=2026-09
 */
class DevWorkSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(?int $account = null, ?string $month = null): void
    {
        $tenant = $account !== null
            ? Account::query()->findOrFail($account)
            : Account::query()->orderBy('id')->firstOrFail();

        $accountId = $tenant->getKey();
        $reference = $month !== null
            ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()->startOfDay()
            : now()->startOfMonth()->startOfDay();

        $departments = collect(['Fiscal', 'Contábil', 'Pessoal'])->mapWithKeys(
            fn (string $name): array => [$name => Department::withoutGlobalScopes()->firstOrCreate(
                ['account_id' => $accountId, 'name' => $name],
                ['color' => match ($name) {
                    'Fiscal' => 'primary',
                    'Contábil' => 'success',
                    default => 'info',
                }]
            )]
        );

        $marker = Tag::withoutGlobalScopes()->firstOrCreate(
            ['account_id' => $accountId, 'name' => 'seed-dev'],
            ['color' => 'neutral']
        );

        ProcessTemplate::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('name', 'like', 'Modelo Dev %')
            ->delete();

        $templates = collect([
            [
                'name' => 'Modelo Dev PGDAS',
                'description' => 'Apuração e transmissão do PGDAS (dados de desenvolvimento).',
                'steps' => [
                    ['title' => 'Apurar PGDAS', 'department' => 'Fiscal', 'due_day' => 10, 'priority' => 'high', 'order' => 1],
                    ['title' => 'Transmitir PGDAS', 'department' => 'Fiscal', 'due_day' => 20, 'priority' => 'urgent', 'order' => 2],
                ],

            ],
            [
                'name' => 'Modelo Dev Folha',
                'description' => 'Fechamento e envio da folha (dados de desenvolvimento).',
                'steps' => [
                    ['title' => 'Fechar folha', 'department' => 'Pessoal', 'due_day' => 5, 'priority' => 'medium', 'order' => 1],
                    ['title' => 'Enviar eSocial', 'department' => 'Pessoal', 'due_day' => 15, 'priority' => 'high', 'order' => 2],
                ],
            ],
        ])->map(function (array $blueprint) use ($accountId, $departments) {
            $template = ProcessTemplate::withoutGlobalScopes()->create([
                'account_id' => $accountId,
                'name' => $blueprint['name'],
                'description' => $blueprint['description'],
                'cascade' => false,
                'generate_day' => 1,
                'due_day' => 20,
                'is_active' => true,
            ]);

            foreach ($blueprint['steps'] as $step) {
                $departmentId = $departments->get($step['department'])?->getKey();
                unset($step['department']);

                $template->steps()->create(array_merge(
                    ['account_id' => $accountId, 'department_id' => $departmentId],
                    $step
                ));
            }

            return $template;
        });

        $service = app(ProcessGenerationService::class);
        $generated = $templates->flatMap(fn (ProcessTemplate $template) => $service->generate($template, $reference->copy()));

        $this->command?->info(sprintf(
            'Work dev: %d departamentos, %d modelos, %d processos em %s na conta %d (%s).',
            $departments->keys()->count(),
            $templates->count(),
            $generated->count(),
            $reference->format('Y-m'),
            $accountId,
            $tenant->name
        ));
    }
}
