<?php

namespace App\Enums;

enum FiscalSkipReason: string
{
    case Blocked = 'blocked';
    case NoCertificate = 'no_certificate';
    case Interrupted = 'interrupted';
}
