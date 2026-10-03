<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'is_super_admin' => $this->is_super_admin,
            'current_account_id' => $this->current_account_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'account_links' => $this->whenLoaded('accountLinks', fn () => $this->accountLinks->map(fn ($link) => [
                'id' => $link->getKey(),
                'account_id' => $link->account_id,
                'user_id' => $link->user_id,
                'role' => $link->role,
                'inviter_id' => $link->inviter_id,
                'created_at' => $link->created_at,
                'updated_at' => $link->updated_at,
                'account' => $link->relationLoaded('account') && $link->account !== null ? [
                    'id' => $link->account->getKey(),
                    'name' => $link->account->name,
                    'status' => $link->account->status,
                    'settings' => $link->account->settingsVisibleToTenant(),
                    'platform_billing_contact' => $link->account->platformBillingContact(),
                    'created_at' => $link->account->created_at,
                    'updated_at' => $link->account->updated_at,
                ] : null,
            ])->values()),
        ];
    }
}
