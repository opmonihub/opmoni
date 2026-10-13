<?php

namespace App\Http\Requests\Tenant;

use App\Models\Tag;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Tag::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:40',
                Rule::unique('tags', 'name')->where(
                    fn ($query) => $query->where('account_id', resolve(CurrentTenant::class)->accountId)
                ),
            ],
            'color' => ['required', Rule::in(['neutral', 'primary', 'success', 'info', 'warning', 'error'])],
        ];
    }
}
