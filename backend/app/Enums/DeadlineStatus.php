<?php

namespace App\Enums;

enum DeadlineStatus: string
{
    case Missing = 'missing';
    case Valid = 'valid';
    case Expiring = 'expiring';
    case Expired = 'expired';
}
