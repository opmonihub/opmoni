<?php

namespace App\Enums\Erp;

enum ErpSubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Canceled = 'canceled';

    public function allowsOperatingWrites(): bool
    {
        return $this === self::Active;
    }
}
