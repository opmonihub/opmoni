<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Tenant\CurrentTenant;

trait HasTenantRole
{
    protected function tenantRole(User $user): ?string
    {
        $tenantId = resolve(CurrentTenant::class)->accountId;

        return $tenantId ? $user->accountRole($tenantId) : null;
    }

    protected function isTenantModel(int $accountId): bool
    {
        return resolve(CurrentTenant::class)->accountId === $accountId;
    }
}
