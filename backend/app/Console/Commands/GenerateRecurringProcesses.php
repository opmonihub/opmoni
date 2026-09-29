<?php

namespace App\Console\Commands;

use App\Models\ProcessTemplate;
use App\Services\ProcessGenerationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateRecurringProcesses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'work:generate-recurrences {--month= : Referência YYYY-MM (padrão: mês corrente).}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera os processos do mês para templates ativos com generate_day vencido.';

    /**
     * Execute the console command.
     */
    public function handle(ProcessGenerationService $generation): int
    {
        $monthOption = (string) ($this->option('month') ?? '');

        if ($monthOption !== '' && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthOption)) {
            $this->error('Mês inválido. Use o formato YYYY-MM.');

            return self::FAILURE;
        }

        $now = now();
        $month = $monthOption !== ''
            ? Carbon::createFromFormat('!Y-m', $monthOption)->startOfMonth()->startOfDay()
            : $now->copy()->startOfMonth()->startOfDay();

        $templates = ProcessTemplate::query()
            ->where('is_active', true)
            ->when(
                $monthOption === '' && $month->isSameMonth($now),
                fn ($query) => $query->where('generate_day', '<=', $now->day)
            )
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($templates as $template) {
            $created = $generation->generate($template, $month)->count();
            $total += $created;
            $this->line("Template {$template->getKey()}: {$created} processo(s) em {$month->format('Y-m')}.");
        }

        $this->info("Concluído: {$total} processo(s) em {$month->format('Y-m')}.");

        return self::SUCCESS;
    }
}
