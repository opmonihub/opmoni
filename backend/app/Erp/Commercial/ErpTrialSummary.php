<?php

namespace App\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use Carbon\CarbonImmutable;

final class ErpTrialSummary
{
    /** @return array{id:int,started_at:string,ends_at:string,converted_at:?string,expired_at:?string,situation:string}|null */
    public static function forLinkedContract(?ErpSubscription $subscription, OfficeErpContract $contract): ?array
    {
        if ($subscription === null || (int) $subscription->office_erp_contract_id !== (int) $contract->id) {
            return null;
        }

        $subscription->loadMissing('trial');
        $trial = $subscription->trial;
        if ($trial === null || (int) $trial->erp_company_id !== (int) $contract->erp_company_id) {
            return null;
        }

        return self::from($trial);
    }

    /** @return array{id:int,started_at:string,ends_at:string,converted_at:?string,expired_at:?string,situation:string}|null */
    public static function from(?ErpSubscriptionTrial $trial): ?array
    {
        if ($trial === null) {
            return null;
        }

        $situation = $trial->converted_at !== null
            ? 'converted'
            : ($trial->expired_at !== null || ! $trial->ends_at->isAfter(CarbonImmutable::now('UTC')) ? 'expired' : 'trialing');

        return [
            'id' => (int) $trial->getKey(),
            'started_at' => $trial->started_at->toIso8601String(),
            'ends_at' => $trial->ends_at->toIso8601String(),
            'converted_at' => $trial->converted_at?->toIso8601String(),
            'expired_at' => $trial->expired_at?->toIso8601String(),
            'situation' => $situation,
        ];
    }
}
