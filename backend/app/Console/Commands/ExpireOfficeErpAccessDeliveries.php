<?php

namespace App\Console\Commands;

use App\Erp\Commercial\OfficeErpAccessDeliveries;
use Illuminate\Console\Command;

class ExpireOfficeErpAccessDeliveries extends Command
{
    protected $signature = 'office-erp:expire-access-deliveries {--batch=100}';

    protected $description = 'Expire encrypted Office ERP access deliveries';

    public function handle(OfficeErpAccessDeliveries $deliveries): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 100) {
            $this->error('The batch must be between 1 and 100.');

            return self::FAILURE;
        }

        $count = $deliveries->expireDue($batch);
        $this->info('Expired '.$count.' access deliveries.');

        return self::SUCCESS;
    }
}
