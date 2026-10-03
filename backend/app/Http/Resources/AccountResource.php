<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'status' => $this->status,
            'settings' => $this->settingsVisibleToTenant(),
            'platform_billing_contact' => $this->platformBillingContact(),
            'platform_owner_invite' => $this->pendingOwnerInvitePreview(),
            'members_count' => $this->whenCounted('members'),
            'subscription' => $this->whenLoaded('subscription', fn () => $this->subscription === null ? null : [
                'id' => $this->subscription->getKey(),
                'account_id' => $this->subscription->account_id,
                'plan_id' => $this->subscription->plan_id,
                'status' => $this->subscription->status,
                'created_at' => $this->subscription->created_at,
                'updated_at' => $this->subscription->updated_at,
                'plan' => $this->subscription->relationLoaded('plan') && $this->subscription->plan !== null ? [
                    'id' => $this->subscription->plan->getKey(),
                    'slug' => $this->subscription->plan->slug,
                    'name' => $this->subscription->plan->name,
                    'limits' => $this->subscription->plan->limits,
                    'created_at' => $this->subscription->plan->created_at,
                    'updated_at' => $this->subscription->plan->updated_at,
                ] : null,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
