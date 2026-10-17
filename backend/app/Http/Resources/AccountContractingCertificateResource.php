<?php

namespace App\Http\Resources;

use App\Models\AccountContractingCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountContractingCertificate */
class AccountContractingCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'configured' => $this->exists,
            'document' => $this->document, 'subject' => $this->subject, 'serial' => $this->serial_number,
            'not_before' => $this->valid_from?->toISOString(), 'not_after' => $this->valid_until?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
