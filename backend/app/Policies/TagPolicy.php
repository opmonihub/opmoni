<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

class TagPolicy
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

    public function update(User $user, Tag $tag): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($tag->account_id);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true)
            && $this->isTenantModel($tag->account_id);
    }
}
