<?php

namespace App\Http\Resources\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\Commercial\OfficeErpMoney;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\ErpSubscription;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ErpSubscription */
class ErpSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['plan.modules', 'company', 'trial']);

        $data = [
            'id' => $this->id,
            'status' => $this->status,
            'company' => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'status' => $this->company->status,
            ],
            'plan' => [
                'id' => $this->plan->id,
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
                'modules' => $this->plan->modules->map(fn ($module): array => [
                    'module_key' => $module->module_key,
                    'quota' => $module->quota,
                ])->values()->all(),
            ],
        ];

        $context = $request->attributes->get(CurrentCompany::class);
        if ($context instanceof CurrentCompany
            && $context->accessKind === 'direct'
            && $context->membership?->roleEnum() === ErpCompanyRole::Owner
            && $context->company->id === $this->company->id
            && $this->trial !== null) {
            $trial = $this->trial;
            $data['trial'] = [
                'started_at' => $trial->started_at->toIso8601String(),
                'ends_at' => $trial->ends_at->toIso8601String(),
                'converted_at' => $trial->converted_at?->toIso8601String(),
                'expired_at' => $trial->expired_at?->toIso8601String(),
                'situation' => $trial->converted_at !== null
                    ? 'converted'
                    : ($trial->expired_at !== null || $trial->ends_at->isPast() ? 'expired' : 'trialing'),
            ];
        }

        if ($context instanceof CurrentCompany
            && $context->accessKind === 'direct'
            && $context->membership?->roleEnum() === ErpCompanyRole::Owner
            && $this->office_erp_contract_id !== null) {
            $contract = OfficeErpContract::query()
                ->whereKey($this->office_erp_contract_id)
                ->where('erp_company_id', $context->company->id)
                ->whereIn('agreement_status', ['accepted', 'effective', 'canceled'])
                ->first();
            abort_unless($contract, 404);

            $revision = OfficeErpContractRevision::query()
                ->where('account_id', $contract->account_id)
                ->where('contract_id', $contract->id)
                ->whereKey($contract->current_revision_id)
                ->first();
            abort_unless($revision, 404);

            $data['retail_contract'] = [
                'agreement_status' => (string) $contract->agreement_status,
                'terms' => [
                    'price' => OfficeErpMoney::canonical((string) $revision->getRawOriginal('price'), 'price'),
                    'currency' => (string) $revision->currency,
                    'interval_months' => (int) $revision->interval_months,
                    'due_in_days' => (int) $revision->due_in_days,
                    'module_keys' => array_values($revision->module_keys ?? []),
                ],
                'service_anchor_on' => $contract->service_anchor_on?->toDateString(),
                'billing_timezone' => (string) $contract->billing_timezone,
            ];
        }

        return $data;
    }
}
