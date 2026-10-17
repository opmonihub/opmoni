<?php

namespace App\Enums;

enum FiscalProcessingState: string
{
    case AwaitingScience = 'awaiting_science';
    case ScienceQueued = 'science_queued';
    case AwaitingXml = 'awaiting_xml';
    case RecoveryQueued = 'recovery_queued';
    case Deferred = 'deferred';
    case Attention = 'attention';
    case Complete = 'complete';
}
