<?php

namespace App\Erp\Commercial;

interface OfficeErpAccessDeliveryTransport
{
    public function assertAvailable(): void;

    public function send(string $recipient, string $subject, string $url): void;
}
