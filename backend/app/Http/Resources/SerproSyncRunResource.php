<?php

namespace App\Http\Resources;

use App\Models\SerproSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A execução como a tela a lê: os seis contadores que somam o total, os
 * carimbos e o motivo da falha. `indeterminate` e `not_processed` são
 * expostos com nome próprio porque nenhum dos dois é falha, e uma tela que
 * os somasse em `failed` estaria mentindo sobre o que o provedor respondeu.
 *
 * @mixin SerproSyncRun
 */
class SerproSyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'previous_run_id' => $this->previous_run_id,
            'state' => $this->state->value,
            'trigger' => $this->trigger->value,
            'obligations' => $this->obligations,
            'reason' => $this->reason,
            'total' => $this->total,
            'synchronized' => $this->synchronized,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'indeterminate' => $this->indeterminate,
            'not_processed' => $this->not_processed,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'items' => SerproSyncRunItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
