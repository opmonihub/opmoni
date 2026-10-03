<?php

namespace App\Enums;

enum FiscalManifestationOutcome: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case AlreadyManifested = 'already_manifested';
}
