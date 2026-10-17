<?php

namespace App\Http\Resources;

use App\Models\SupportAccessLog;
use App\Support\SupportAccessLogMetadata;
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
            'metadata' => SupportAccessLogMetadata::sanitize($this->metadata),
            'ip' => $this->ip,
            'created_at' => $this->created_at,
            'super_admin' => $this->whenLoaded('superAdmin', fn () => $this->superAdmin === null ? null : [
                'id' => $this->superAdmin->getKey(),
                'name' => $this->superAdmin->name,
                'email' => $this->superAdmin->email,
            ]),
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->getKey(),
                'name' => $this->account->name,
                'status' => $this->account->status,
            ]),
        ];
    }
}
