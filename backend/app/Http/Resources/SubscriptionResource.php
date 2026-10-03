<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'account_id' => $this->account_id,
            'plan_id' => $this->plan_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->getKey(),
                'name' => $this->account->name,
                'status' => $this->account->status,
                'settings' => $this->account->settingsVisibleToTenant(),
                'platform_billing_contact' => $this->account->platformBillingContact(),
                'created_at' => $this->account->created_at,
                'updated_at' => $this->account->updated_at,
            ]),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan === null ? null : [
                'id' => $this->plan->getKey(),
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
                'limits' => $this->plan->limits,
                'created_at' => $this->plan->created_at,
                'updated_at' => $this->plan->updated_at,
            ]),
        ];
    }
}
