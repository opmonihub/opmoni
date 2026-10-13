<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

class DepartmentPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function create(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    public function update(User $user, Department $department): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($department->account_id);
    }

    public function delete(User $user, Department $department): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($department->account_id);
    }
}
