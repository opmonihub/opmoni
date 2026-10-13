<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MemberDirectoryResource extends JsonResource
{
    /**
     * Directory entry without contact data (no email).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'role' => $this->pivot->role,
            'departments' => $this->whenLoaded('departments', fn () => $this->departments->sortBy('name')->map(fn ($department): array => [
                'id' => $department->getKey(),
                'name' => $department->name,
                'color' => $department->color,
            ])->values()->all(), []),
        ];
    }
}
