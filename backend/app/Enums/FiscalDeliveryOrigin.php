<?php

namespace App\Enums;

enum FiscalDeliveryOrigin: string
{
    case AuthenticatedDistribution = 'authenticated_distribution';
    case ErpIssued = 'erp_issued';
    case Unverified = 'unverified';
}
