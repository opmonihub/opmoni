<?php

namespace App\Http\Resources;

use App\Models\ProcessTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessTemplate */
class ProcessTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'description' => $this->description,
            'cascade' => $this->cascade,
            'generate_day' => $this->generate_day,
            'due_day' => $this->due_day,
            'is_active' => $this->is_active,
            'regimes' => $this->regimes ?? [],
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()),
            'exceptions' => $this->whenLoaded('exceptions', fn () => $this->exceptions->map(fn ($exception) => [
                'client_id' => $exception->client_id,
                'kind' => $exception->kind,
            ])->values()),
            'steps' => ProcessTemplateTaskResource::collection($this->whenLoaded('steps')),
        ];
    }
}
