<?php

namespace App\Policies;

use App\Models\ProcessTemplate;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

class ProcessTemplatePolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function view(User $user, ProcessTemplate $template): bool
    {
        return $this->tenantRole($user) !== null && $this->isTenantModel($template->account_id);
    }

    public function create(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }

    public function update(User $user, ProcessTemplate $template): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($template->account_id);
    }

    public function delete(User $user, ProcessTemplate $template): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($template->account_id);
    }
}
