<?php

namespace App\Http\Requests\Tenant;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class BulkDeleteClientsRequest extends FormRequest
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
        return [
            'ids' => ['sometimes', 'array', 'max:10000'],
            'ids.*' => ['integer', 'distinct'],
            'selection_id' => ['required_without:ids', 'uuid'],
            'excluded_ids' => ['sometimes', 'array', 'max:10000'],
            'excluded_ids.*' => ['integer', 'distinct'],
        ];
    }
}
