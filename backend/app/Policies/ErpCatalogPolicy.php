<?php

namespace App\Policies;

use App\Models\Erp\ErpContact;
use App\Models\User;
use App\Support\CurrentCompany;

class ErpCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCompanyAccess();
    }

    public function view(User $user, ErpContact $contact): bool
    {
        return $this->sameCompany($contact->erp_company_id);
    }

    public function create(User $user): bool
    {
        return $this->canMutate();
    }

    public function update(User $user, ErpContact $contact): bool
    {
        return $this->canMutate() && $this->sameCompany($contact->erp_company_id);
    }

    private function hasCompanyAccess(): bool
    {
        try {
            CurrentCompany::fromRequest();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function canMutate(): bool
    {
        $context = CurrentCompany::fromRequest();
        if ($context->accessKind === 'delegated') {
            return false;
        }

        return $context->membership?->roleEnum()->canMutate() ?? false;
    }

    private function sameCompany(int $companyId): bool
    {
        return CurrentCompany::fromRequest()->company->id === $companyId;
    }
}
