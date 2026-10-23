<?php

namespace App\Jobs;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Exceptions\AccountingMappingBusy;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingDeliveryDispatch;
use App\Support\CurrentAccount;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverErpAccountingDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 30;

    public readonly int $deliveryId;

    public ?int $dispatchId = null;

    public ?int $dispatchGeneration = null;

    public int $attemptSequence = 1;

    public ?string $dispatchLeaseToken = null;

    public function __construct(
        int $deliveryId,
        ?int $dispatchId = null,
        ?int $dispatchGeneration = null,
        int $attemptSequence = 1,
        ?string $dispatchLeaseToken = null,
    ) {
        $this->deliveryId = $deliveryId;
        $this->dispatchId = $dispatchId;
        $this->dispatchGeneration = $dispatchGeneration;
        $this->attemptSequence = $attemptSequence;
        $this->dispatchLeaseToken = $dispatchLeaseToken;
    }

    public function handle(ErpAccountingHandoffService $handoff): void
    {
        // Publication must execute in a neutral worker, never inside an HTTP tenant context.
        abort_if(request()->attributes->has(CurrentAccount::class) || request()->attributes->has(CurrentCompany::class), 409);
        $delivery = ErpAccountingDelivery::query()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }
        $attemptsBefore = $delivery->attempts;
        try {
            $result = $handoff->deliver($delivery, $this->dispatchId, $this->attemptSequence, $this->dispatchGeneration, $this->dispatchLeaseToken);
            if ($result->status === ErpAccountingDelivery::STATUS_FAILED && $result->attempts < ErpAccountingHandoffService::MAX_ATTEMPTS) {
                if ($this->dispatchId !== null) {
                    if ($result->attempts <= $attemptsBefore) {
                        return;
                    }
                    $dispatch = ErpAccountingDeliveryDispatch::query()->find($this->dispatchId);
                    if ($dispatch === null || $dispatch->status !== 'pending') {
                        return;
                    }

                    return;
                }
                $this->release(min(60, 2 ** min($this->attempts(), 5)));
            }
        } catch (AccountingMappingBusy) {
            $this->release(min(60, 2 ** min($this->attempts(), 5)));
        }
    }
}
