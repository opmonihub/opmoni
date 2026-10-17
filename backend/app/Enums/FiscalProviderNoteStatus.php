<?php

namespace App\Enums;

enum FiscalProviderNoteStatus: string
{
    case Authorized = 'authorized';
    case Cancelled = 'cancelled';
    case Denied = 'denied';
    case Unknown = 'unknown';
}
