<?php

namespace App\Http\Resources;

use App\Models\ClientCertificate;
use App\Services\DeadlineState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientCertificate */
class ClientCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'subject' => $this->subject,
            'serial_number' => $this->serial_number,
            'valid_from' => $this->valid_from->toISOString(),
            'valid_until' => $this->valid_until->toISOString(),
            'original_filename' => $this->original_filename,
            'status' => resolve(DeadlineState::class)->for($this->valid_until)->value,
        ];
    }
}
