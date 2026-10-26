<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ErpTrialLifecycle
{
    public function expire(ErpSubscriptionTrial $trial, CarbonImmutable $now): void
    {
        $identity = ErpSubscriptionTrial::query()->whereKey($trial->getKey())->firstOrFail();

        DB::transaction(function () use ($identity, $now): void {
            $account = Account::query()->whereKey($identity->account_id)->lockForUpdate()->firstOrFail();
            $officeSubscription = Subscription::query()->where('account_id', $account->id)->lockForUpdate()->first();
            if ($officeSubscription?->plan_id !== null) {
                Plan::query()->whereKey($officeSubscription->plan_id)->lockForUpdate()->first();
            }

            $company = ErpCompany::query()->whereKey($identity->erp_company_id)->lockForUpdate()->firstOrFail();
            $subscriptionCandidate = ErpSubscription::query()
                ->whereKey($identity->erp_subscription_id)
                ->where('erp_company_id', $company->id)
                ->firstOrFail();

            $contract = null;
            $allocation = null;
            if ($subscriptionCandidate->office_erp_contract_id !== null) {
                $contract = OfficeErpContract::query()->withoutGlobalScope('account')
                    ->whereKey($subscriptionCandidate->office_erp_contract_id)
                    ->where('account_id', $identity->account_id)
                    ->where('erp_company_id', $company->id)
                    ->lockForUpdate()
                    ->first();
                if ($contract !== null) {
                    $allocation = OfficeErpLicenceAllocation::query()->withoutGlobalScope('account')
                        ->where('account_id', $identity->account_id)
                        ->where('contract_id', $contract->id)
                        ->lockForUpdate()
                        ->first();
                }
            }

            $subscription = ErpSubscription::query()
                ->whereKey($identity->erp_subscription_id)
                ->where('erp_company_id', $company->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($subscription->office_erp_contract_id !== $subscriptionCandidate->office_erp_contract_id) {
                return;
            }

            $lockedTrial = ErpSubscriptionTrial::query()->whereKey($identity->id)
                ->where('erp_subscription_id', $subscription->id)
                ->where('erp_company_id', $company->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTrial->converted_at !== null
                || $subscription->status !== 'trialing'
                || $lockedTrial->ends_at->isAfter($now)) {
                return;
            }

            if ($lockedTrial->expired_at === null) {
                $lockedTrial->forceFill(['expired_at' => $now])->save();
            }
            $subscription->forceFill(['status' => 'trial_expired'])->save();

            if ($allocation !== null
                && $allocation->state === 'occupied'
                && (int) $allocation->erp_company_id === (int) $company->id) {
                $allocation->forceFill(['state' => 'released', 'expires_at' => null, 'released_at' => $now])->save();
            }
        }, 3);
    }
}
