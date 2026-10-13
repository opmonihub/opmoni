<?php

namespace App\Http\Resources;

use App\Models\SerproSyncRunItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O item como a tela o lê. `obligation` é publicado em vez de
 * `current_obligation` porque para quem lê aquilo *é* a obrigação do item —
 * o nome da coluna descreve o mecanismo de fronteira que a tela não precisa
 * conhecer.
 *
 * @mixin SerproSyncRunItem
 */
class SerproSyncRunItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'client_id' => $this->client_id,
            'name' => $this->client?->name,
            'tax_id' => $this->client?->tax_id,
            'state' => $this->state->value,
            'obligation' => $this->current_obligation,
            'reason' => $this->reason,
            'provider_code' => $this->provider_code,
            'response_id' => $this->response_id,
            'request_tag' => $this->request_tag,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
