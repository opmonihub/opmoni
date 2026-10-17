<?php

namespace App\Enums;

enum FiscalStorageState: string
{
    case Unverified = 'unverified';
    case Available = 'available';
    case Missing = 'missing';
    case Corrupt = 'corrupt';
}
