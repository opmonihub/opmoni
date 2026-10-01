<?php

namespace App\Http\Resources;

use App\Models\ProcessTemplateTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessTemplateTask */
class ProcessTemplateTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->title,
            'department_id' => $this->department_id,
            'department' => $this->department === null ? null : [
                'id' => $this->department->getKey(),
                'name' => $this->department->name,
                'color' => $this->department->color,
            ],
            'description' => $this->description,
            'due_day' => $this->due_day,
            'priority' => $this->priority,
            'order' => $this->order,
            'default_assignee_member_id' => $this->default_assignee_member_id,
        ];
    }
}
