<?php

namespace App\Http\Resources;

use App\Models\SupportAccessLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupportAccessLog */
class SupportAccessLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'super_admin_user_id' => $this->super_admin_user_id,
            'account_id' => $this->account_id,
            'action' => $this->action,
            'metadata' => $this->metadata,
            'ip' => $this->ip,
            'created_at' => $this->created_at,
            'super_admin' => $this->whenLoaded('superAdmin', fn () => $this->superAdmin === null ? null : [
                'id' => $this->superAdmin->getKey(),
                'name' => $this->superAdmin->name,
                'email' => $this->superAdmin->email,
                'email_verified_at' => $this->superAdmin->email_verified_at,
                'is_super_admin' => $this->superAdmin->is_super_admin,
                'current_account_id' => $this->superAdmin->current_account_id,
                'created_at' => $this->superAdmin->created_at,
                'updated_at' => $this->superAdmin->updated_at,
            ]),
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->getKey(),
                'name' => $this->account->name,
                'status' => $this->account->status,
                'settings' => $this->account->settingsVisibleToTenant(),
                'platform_billing_contact' => $this->account->platformBillingContact(),
                'created_at' => $this->account->created_at,
                'updated_at' => $this->account->updated_at,
            ]),
        ];
    }
}
