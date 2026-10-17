<?php

namespace App\Enums;

enum FiscalScienceRolloutMode: string
{
    case Disabled = 'disabled';
    case Pilot = 'pilot';
    case Fleet = 'fleet';
}
