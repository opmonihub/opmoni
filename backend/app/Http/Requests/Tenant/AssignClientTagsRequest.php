<?php

namespace App\Http\Requests\Tenant;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignClientTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('categorize', Client::class);
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
            'tag_ids' => ['required', 'array', 'min:1', 'max:50'],
            'tag_ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['attach', 'detach'])],
        ];
    }
}
