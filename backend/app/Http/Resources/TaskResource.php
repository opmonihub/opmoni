<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
class TaskResource extends JsonResource
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
            'title' => $this->title,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department === null ? null : [
                'id' => $this->department->getKey(),
                'name' => $this->department->name,
                'color' => $this->department->color,
            ]),
            'description' => $this->description,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'due_on' => $this->due_on?->toDateString(),
            'priority' => $this->priority instanceof \BackedEnum ? $this->priority->value : $this->priority,
            'assignee_member_id' => $this->assignee_member_id,
            'completed_at' => $this->completed_at?->toDateTimeString(),
            'dismissal_reason' => $this->dismissal_reason,
            'order' => $this->order,
            'cascade_locked' => $this->when(
                $this->resource->getAttribute('cascade_locked') !== null,
                fn (): bool => (bool) ($this->process?->template?->cascade ?? false) && (bool) $this->cascade_locked
            ),
            'process' => $this->whenLoaded('process', fn () => [
                'id' => $this->process->getKey(),
                'name' => $this->process->name,
                'cascade' => $this->process->relationLoaded('template')
                    ? (bool) ($this->process->template?->cascade ?? false)
                    : null,
                'client' => $this->process->relationLoaded('client') && $this->process->client !== null ? [
                    'id' => $this->process->client->getKey(),
                    'name' => $this->process->client->name,
                ] : null,
            ]),
        ];
    }
}
