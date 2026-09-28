<?php

namespace App\Http\Resources;

use App\Models\ClientEcacPowerOfAttorney;
use App\Services\DeadlineState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientEcacPowerOfAttorney */
class ClientEcacPowerOfAttorneyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'starts_at' => $this->starts_at->toDateString(),
            'expires_at' => $this->expires_at->toDateString(),
            'notes' => $this->notes,
            'status' => resolve(DeadlineState::class)->for($this->expires_at)->value,
            'serpro_code' => $this->serpro_code,
            'integration_state' => $this->integration_state?->value,
        ];
    }
}
