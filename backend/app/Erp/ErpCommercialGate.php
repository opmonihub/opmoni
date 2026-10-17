<?php

namespace App\Erp;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;

class ErpCommercialGate
{
    public function subscriptionForCompany(ErpCompany $company): ?ErpSubscription
    {
        return $company->relationLoaded('subscription')
            ? $company->subscription
            : ErpSubscription::query()->where('erp_company_id', $company->id)->first();
    }

    public function allowsOperatingWrites(ErpCompany $company): bool
    {
        $subscription = $this->subscriptionForCompany($company);
        if ($subscription === null) {
            return false;
        }

        return ErpSubscriptionStatus::from($subscription->status)->allowsOperatingWrites();
    }

    public function moduleEnabled(ErpCompany $company, string $moduleKey): bool
    {
        if (! $this->allowsOperatingWrites($company)) {
            return false;
        }

        $subscription = $this->subscriptionForCompany($company);
        if ($subscription === null) {
            return false;
        }

        $subscription->loadMissing('plan.modules');

        return $subscription->plan?->modules
            ->contains(fn ($module): bool => $module->module_key === $moduleKey) ?? false;
    }
}
