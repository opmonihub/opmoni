<?php

namespace App\Console\Commands;

use App\Erp\Accounting\ErpAccountingDispatchOutbox as Outbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:dispatch-erp-accounting-delivery-outbox')]
#[Description('Dispatches pending ERP accounting delivery outbox rows.')]
class DispatchErpAccountingDeliveryOutbox extends Command
{
    public function handle(Outbox $outbox): int
    {
        $this->components->info('Accounting delivery dispatches queued: '.$outbox->dispatchDue());

        return self::SUCCESS;
    }
}
