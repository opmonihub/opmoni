<?php

namespace App\Policies;

use App\Models\ClientSavedFilter;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;
use App\Tenant\CurrentTenant;

class ClientSavedFilterPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function delete(User $user, ClientSavedFilter $filter): bool
    {
        return $this->tenantRole($user) !== null
            && $filter->user_id === $user->getKey()
            && resolve(CurrentTenant::class)->accountId === $filter->account_id;
    }
}
