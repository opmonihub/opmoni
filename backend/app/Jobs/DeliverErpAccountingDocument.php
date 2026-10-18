<?php

namespace App\Jobs;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Exceptions\AccountingMappingBusy;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Support\CurrentAccount;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverErpAccountingDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(ErpAccountingHandoffService $handoff): void
    {
        // Publication must execute in a neutral worker, never inside an HTTP tenant context.
        abort_if(request()->attributes->has(CurrentAccount::class) || request()->attributes->has(CurrentCompany::class), 409);
        $delivery = ErpAccountingDelivery::query()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }
        try {
            $result = $handoff->deliver($delivery);
            if ($result->status === ErpAccountingDelivery::STATUS_FAILED && $result->attempts < ErpAccountingHandoffService::MAX_ATTEMPTS) {
                $this->release(min(60, 2 ** min($this->attempts(), 5)));
            }
        } catch (AccountingMappingBusy) {
            $this->release(min(60, 2 ** min($this->attempts(), 5)));
        }
    }
}
