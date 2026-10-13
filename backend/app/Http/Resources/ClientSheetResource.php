<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Services\DeadlineState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientSheetResource extends JsonResource
{
    /**
     * Campos que a grade precisa para filtrar, ordenar e selecionar.
     * O detalhe do cliente continua em GET /clients/{client}.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deadlines = once(fn (): DeadlineState => resolve(DeadlineState::class));

        return [
            'id' => $this->getKey(),
            'person_type' => $this->person_type?->value,
            'tax_id' => $this->tax_id,
            'name' => $this->name,
            'status' => $this->status?->value,
            'tax_regime' => $this->tax_regime?->value,
            'certificate' => $this->currentCertificate === null ? null : [
                'valid_until' => $this->currentCertificate->valid_until->toISOString(),
            ],
            'certificate_status' => $deadlines->for($this->currentCertificate?->valid_until)->value,
            'ecac_power_of_attorney' => $this->power_summary ?? null,
            'ecac_power_of_attorney_status' => ($this->power_summary ?? ['status' => 'missing'])['status'],
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])->values()),
        ];
    }
}
