<?php

namespace App\Erp;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use Carbon\CarbonImmutable;

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
        return $this->operatingWriteDenialCode($company) === null;
    }

    public function operatingWriteDenialCode(ErpCompany $company): ?string
    {
        $subscription = $this->subscriptionForCompany($company);
        if ($subscription === null) {
            return 'erp_subscription_inactive';
        }

        $status = ErpSubscriptionStatus::tryFrom((string) $subscription->status);
        if ($status === ErpSubscriptionStatus::Active) {
            $trial = $this->trialRecordForSubscription($company, $subscription);
            if ($trial !== null) {
                return $trial->expired_at !== null || ! $trial->ends_at->isAfter(CarbonImmutable::now('UTC'))
                    ? 'erp_trial_expired'
                    : 'erp_subscription_inactive';
            }

            if ($this->serviceHasNotStarted($company, $subscription)) {
                return 'erp_subscription_inactive';
            }

            return null;
        }

        if ($status === ErpSubscriptionStatus::Trialing) {
            $trial = $this->trialRecordForSubscription($company, $subscription);
            if ($trial === null) {
                return 'erp_subscription_inactive';
            }
            if ($trial->expired_at !== null || ! $trial->ends_at->isAfter(CarbonImmutable::now('UTC'))) {
                return 'erp_trial_expired';
            }

            return $this->hasValidTrialContractAndAllocation($company, $subscription, $trial)
                ? null
                : 'erp_subscription_inactive';
        }

        if ($status === ErpSubscriptionStatus::TrialExpired) {
            $trial = $this->trialRecordForSubscription($company, $subscription);
            if ($trial !== null && $trial->converted_at === null) {
                return 'erp_trial_expired';
            }
        }

        return 'erp_subscription_inactive';
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
            $trial = $this->trialRecordForSubscription($company, $subscription);
            $contract = OfficeErpContract::query()->with(['currentRevision', 'pendingRevision'])->whereKey($subscription->office_erp_contract_id)
                ->where('erp_company_id', $company->id)
                ->where('agreement_status', $trial === null ? 'effective' : 'accepted')
                ->where('manual_hold', false)
                ->first();
            if ($contract === null || $contract->current_revision_id === null
                || ($trial !== null && ! $this->hasValidTrialContractAndAllocation($company, $subscription, $trial))) {
                return false;
            }
            if ($trial === null && $this->serviceHasNotStarted($company, $subscription)) {
                return false;
            }
            $revision = $this->effectiveRevision($contract);

            return in_array($moduleKey, $revision?->module_keys ?? [], true);
        }

        $subscription->loadMissing('plan.modules');

        return $subscription->plan?->modules
            ->contains(fn ($module): bool => $module->module_key === $moduleKey) ?? false;
    }

    public function moduleEnabledForRead(ErpCompany $company, string $moduleKey): bool
    {
        $subscription = $this->subscriptionForCompany($company);
        if ($subscription === null) {
            return false;
        }

        if ($subscription->status === ErpSubscriptionStatus::Active->value && $this->serviceHasNotStarted($company, $subscription)) {
            return false;
        }

        $trial = $this->trialRecordForSubscription($company, $subscription);
        if ($trial === null || ! in_array($subscription->status, [
            ErpSubscriptionStatus::Trialing->value,
            ErpSubscriptionStatus::TrialExpired->value,
        ], true)) {
            return $this->moduleEnabled($company, $moduleKey);
        }

        $contract = OfficeErpContract::query()->whereKey($subscription->office_erp_contract_id)
            ->where('account_id', $trial->account_id)
            ->where('erp_company_id', $company->id)
            ->where('agreement_status', 'accepted')
            ->where('provisioning_source', 'office_trial')
            ->where('manual_hold', false)
            ->whereNotNull('current_revision_id')
            ->first();
        if ($contract === null) {
            return false;
        }

        $expired = $trial->expired_at !== null
            || ! $trial->ends_at->isAfter(CarbonImmutable::now('UTC'))
            || $subscription->status === ErpSubscriptionStatus::TrialExpired->value;
        $allocation = OfficeErpLicenceAllocation::query()
            ->where('account_id', $trial->account_id)
            ->where('contract_id', $contract->id)
            ->where('erp_company_id', $company->id)
            ->whereIn('state', $expired ? ['occupied', 'released'] : ['occupied'])
            ->exists();
        if (! $allocation) {
            return false;
        }

        $contract->loadMissing(['currentRevision', 'pendingRevision']);
        $revision = $this->effectiveRevision($contract);

        return in_array($moduleKey, $revision?->module_keys ?? [], true);
    }

    private function effectiveRevision(OfficeErpContract $contract): ?OfficeErpContractRevision
    {
        $pending = $contract->pendingRevision;
        if ($pending !== null && $pending->service_period_start !== null
            && $pending->service_period_start->toDateString() <= CarbonImmutable::now($contract->billing_timezone)->toDateString()) {
            return $pending;
        }

        return $contract->currentRevision;
    }

    private function serviceHasNotStarted(ErpCompany $company, ErpSubscription $subscription): bool
    {
        if ($subscription->office_erp_contract_id === null) {
            return false;
        }
        $contract = OfficeErpContract::query()->whereKey($subscription->office_erp_contract_id)
            ->where('erp_company_id', $company->id)
            ->where('agreement_status', 'effective')
            ->first(['id', 'billing_timezone', 'service_anchor_on']);

        return $contract?->service_anchor_on !== null
            && $contract->service_anchor_on->toDateString() > CarbonImmutable::now($contract->billing_timezone)->toDateString();
    }

    private function trialRecordForSubscription(ErpCompany $company, ErpSubscription $subscription): ?ErpSubscriptionTrial
    {
        return ErpSubscriptionTrial::query()
            ->where('erp_subscription_id', $subscription->id)
            ->where('erp_company_id', $company->id)
            ->where('cnpj', $company->cnpj)
            ->whereNull('converted_at')
            ->first();
    }

    private function hasValidTrialContractAndAllocation(
        ErpCompany $company,
        ErpSubscription $subscription,
        ErpSubscriptionTrial $trial,
    ): bool {
        if ($subscription->office_erp_contract_id === null) {
            return false;
        }

        $contract = OfficeErpContract::query()->whereKey($subscription->office_erp_contract_id)
            ->where('account_id', $trial->account_id)
            ->where('erp_company_id', $company->id)
            ->where('agreement_status', 'accepted')
            ->whereNotNull('current_revision_id')
            ->where('manual_hold', false)
            ->first();
        if ($contract === null) {
            return false;
        }

        return OfficeErpLicenceAllocation::query()
            ->where('account_id', $trial->account_id)
            ->where('contract_id', $contract->id)
            ->where('erp_company_id', $company->id)
            ->where('state', 'occupied')
            ->exists();
    }
}
