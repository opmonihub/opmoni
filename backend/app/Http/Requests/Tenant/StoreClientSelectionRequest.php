<?php

namespace App\Http\Requests\Tenant;

use App\Models\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class StoreClientSelectionRequest extends IndexClientRequest
{
    public function authorize(): bool
    {
        return Gate::allows('bulkDelete', Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Arr::only(parent::rules(), ['q', 'status', 'tax_regime', 'deadline_status', 'certificate_status', 'poa_status', 'tag_id', 'view']);
    }
}
