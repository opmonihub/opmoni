<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
class PlatformAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['pendingOwnerActivation', 'subscription.plan']);
        if (! isset($this->resource->memberships_count)) {
            $this->resource->loadCount('memberships');
        }
        $pending = $this->pendingOwnerActivation;
        $contact = $this->platformBillingContact() ?? [];
        $subscription = $this->subscription;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'members_count' => $this->memberships_count,
            'pending_owner' => $pending ? ['name' => $pending->owner_name, 'email' => $pending->email, 'expires_at' => $pending->expires_at->toISOString()] : null,
            'phone' => $contact['phone'] ?? null,
            'phone_whatsapp' => ! empty($contact['phone']) && (bool) ($contact['phone_whatsapp'] ?? false),
            'state' => $contact['state'] ?? null,
            'city' => $contact['city'] ?? null,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan' => $subscription->plan ? ['id' => $subscription->plan->id, 'slug' => $subscription->plan->slug, 'name' => $subscription->plan->name] : null,
            ] : null,
        ];
    }
}
