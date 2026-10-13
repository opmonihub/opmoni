<?php

namespace App\Policies;

use App\Models\Process;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

class ProcessPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function view(User $user, Process $process): bool
    {
        return $this->tenantRole($user) !== null && $this->isTenantModel($process->account_id);
    }

    public function create(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    public function update(User $user, Process $process): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($process->account_id);
    }

    public function delete(User $user, Process $process): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($process->account_id);
    }
}
