<?php

namespace App\Http\Resources;

use App\Models\FiscalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalDocument */
class FiscalProcessingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client_name' => $this->client?->name,
            'chave_acesso' => $this->chave_acesso,
            'model' => $this->model?->value,
            'source' => $this->source?->value,
            'stage' => $this->stage?->value,
            'emissao_at' => $this->emissao_at?->toIso8601String(),
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'processing_state' => $this->processing_state?->value,
            'processing_reason' => $this->processing_reason?->value,
            'processing_attempts' => $this->processing_attempts,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'storage_state' => $this->storage_state?->value,
        ];
    }
}
