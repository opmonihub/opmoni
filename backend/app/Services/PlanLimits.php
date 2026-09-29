<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Validation\ValidationException;

class PlanLimits
{
    /**
     * Throw when creating another `$key` record would exceed the
     * account's active plan limit. Absent limit means unlimited.
     */
    public static function assertCanCreate(Account $account, string $key): void
    {
        $limits = $account->subscription?->plan?->limits ?? [];
        $limit = $limits[$key] ?? null;

        if ($limit === null) {
            return;
        }

        $limit = (int) $limit;

        $count = match ($key) {
            'users' => $account->members()->count(),
            'clients' => $account->clients()->count(),
        };

        if ($count >= $limit) {
            throw ValidationException::withMessages(['limit' => ['Limite do plano atingido.']]);
        }
    }
}
