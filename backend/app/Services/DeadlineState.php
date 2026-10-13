<?php

namespace App\Services;

use App\Enums\DeadlineStatus;
use Carbon\CarbonInterface;

final class DeadlineState
{
    public function for(?CarbonInterface $expiresAt): DeadlineStatus
    {
        if ($expiresAt === null) {
            return DeadlineStatus::Missing;
        }

        $today = now()->startOfDay();
        $date = $expiresAt->copy()->startOfDay();

        if ($date->lt($today)) {
            return DeadlineStatus::Expired;
        }

        return $date->lte($today->copy()->addDays(30))
            ? DeadlineStatus::Expiring
            : DeadlineStatus::Valid;
    }
}
