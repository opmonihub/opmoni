<?php

namespace App\Console\Commands;

use App\Jobs\GenerateOfficeErpInvoices;
use App\Models\Erp\Commercial\OfficeErpContract;
use Illuminate\Console\Command;

class GenerateOfficeErpInvoicesCommand extends Command
{
    protected $signature = 'office-erp:generate-invoices {--batch=50}';

    protected $description = 'Dispatch bounded recurring invoice generation for effective office ERP contracts.';

    public function handle(): int
    {
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batchSize === false || $batchSize < 1 || $batchSize > 100) {
            $this->error('Batch must be an integer between 1 and 100.');

            return self::FAILURE;
        }

        $dispatched = 0;
        OfficeErpContract::query()->withoutGlobalScope('account')
            ->where('agreement_status', 'effective')
            ->whereNotNull('service_anchor_on')
            ->whereNotNull('next_period_index')
            ->whereNotNull('next_period_month_offset')
            ->orderBy('id')
            ->chunkById($batchSize, function ($contracts) use ($batchSize, &$dispatched): void {
                foreach ($contracts as $contract) {
                    GenerateOfficeErpInvoices::dispatch((int) $contract->getKey(), $batchSize);
                    $dispatched++;
                }
            }, 'id');

        $this->info("Dispatched recurring billing for {$dispatched} contract(s).");

        return self::SUCCESS;
    }
}
