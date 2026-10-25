<?php

namespace App\Erp;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
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

        if ($subscription->office_erp_contract_id !== null) {
            $contract = OfficeErpContract::query()->whereKey($subscription->office_erp_contract_id)
                ->where('erp_company_id', $company->id)
                ->where('agreement_status', 'effective')
                ->first();
            if ($contract === null || $contract->current_revision_id === null) {
                return false;
            }
            $revision = OfficeErpContractRevision::query()->whereKey($contract->current_revision_id)
                ->where('contract_id', $contract->id)->first();

            return in_array($moduleKey, $revision?->module_keys ?? [], true);
        }

        $subscription->loadMissing('plan.modules');

        return $subscription->plan?->modules
            ->contains(fn ($module): bool => $module->module_key === $moduleKey) ?? false;
    }
}
