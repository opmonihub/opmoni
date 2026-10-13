<?php

namespace App\Http\Resources;

use App\Models\Process;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Process */
class ProcessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'status' => $this->status,
            'due_on' => $this->due_on?->toDateString(),
            'reference_month' => $this->reference_month?->format('Y-m'),
            'template' => $this->whenLoaded('template', fn () => $this->template === null ? null : [
                'id' => $this->template->getKey(),
                'name' => $this->template->name,
                'cascade' => (bool) $this->template->cascade,
            ]),
            'client' => $this->whenLoaded('client', fn () => $this->client === null ? null : [
                'id' => $this->client->getKey(),
                'name' => $this->client->name,
            ]),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'progress' => $this->when(isset($this->progress), $this->progress),
        ];
    }
}
