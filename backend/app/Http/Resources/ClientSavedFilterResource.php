<?php

namespace App\Http\Resources;

use App\Models\ClientSavedFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientSavedFilter */
class ClientSavedFilterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'q' => $this->q,
            'filters' => $this->filters,
        ];
    }
}
