<?php

namespace App\Jobs;

use App\Erp\Commercial\OfficeErpRecurringBilling;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateOfficeErpInvoices implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $contractId, public readonly int $batchSize = 50) {}

    public function handle(OfficeErpRecurringBilling $billing): void
    {
        $result = $billing->generate($this->contractId, CarbonImmutable::now(), $this->batchSize);
        if ($result['pending']) {
            Log::info('Office ERP recurring billing has catch-up work pending.', [
                'contract_id' => $this->contractId,
                'generated_count' => count($result['generated']),
            ]);
        }
    }
}
