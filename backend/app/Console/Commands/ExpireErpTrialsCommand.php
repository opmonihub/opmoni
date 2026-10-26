<?php

namespace App\Console\Commands;

use App\Erp\Commercial\ErpTrialLifecycle;
use App\Models\Erp\ErpSubscriptionTrial;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExpireErpTrialsCommand extends Command
{
    protected $signature = 'office-erp:expire-trials {--batch=50}';

    protected $description = 'Expira testes ERP vencidos e libera suas licenças.';

    public function handle(ErpTrialLifecycle $lifecycle): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 100) {
            $this->error('O lote deve estar entre 1 e 100.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now('UTC');
        $trials = ErpSubscriptionTrial::query()
            ->whereNull('converted_at')
            ->whereNull('expired_at')
            ->where('ends_at', '<=', $now)
            ->orderBy('ends_at')
            ->orderBy('id')
            ->limit($batch)
            ->get();

        foreach ($trials as $trial) {
            $lifecycle->expire($trial, $now);
        }

        $this->info('Testes expirados: '.$trials->count().'.');

        return self::SUCCESS;
    }
}
