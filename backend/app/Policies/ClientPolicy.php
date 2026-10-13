<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

class ClientPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function view(User $user, Client $client): bool
    {
        return $this->tenantRole($user) !== null && $this->isTenantModel($client->account_id);
    }

    public function create(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    public function update(User $user, Client $client): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($client->account_id);
    }

    public function delete(User $user, Client $client): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($client->account_id);
    }

    public function bulkDelete(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    public function categorize(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }
}
