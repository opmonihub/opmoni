<?php

namespace App\Jobs;

use App\Erp\Commercial\OfficeErpAccessDeliveries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeliverOfficeErpAccessDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(OfficeErpAccessDeliveries $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }

    public function failed(?Throwable $exception): void
    {
        app(OfficeErpAccessDeliveries::class)->markWorkerFailureUnknown($this->deliveryId);
    }
}
