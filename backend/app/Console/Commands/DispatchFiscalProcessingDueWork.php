<?php

namespace App\Console\Commands;

use App\Services\Fiscal\Processing\FiscalProcessingDueWork;
use Illuminate\Console\Command;

final class DispatchFiscalProcessingDueWork extends Command
{
    protected $signature = 'fiscal:dispatch-processing-due';

    protected $description = 'Despacha ciência e recuperação de XML para resumos fiscais com trabalho vencido';

    public function handle(FiscalProcessingDueWork $dueWork): int
    {
        $count = $dueWork->dispatchDue();

        $this->info("Jobs despachados: {$count}");

        return self::SUCCESS;
    }
}
