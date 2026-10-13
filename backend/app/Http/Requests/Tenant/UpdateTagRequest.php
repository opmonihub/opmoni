<?php

namespace App\Http\Requests\Tenant;

use App\Models\Tag;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tag = $this->route('tag');

        return $tag instanceof Tag && Gate::allows('update', $tag);
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
        $tag = $this->route('tag');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:40',
                Rule::unique('tags', 'name')
                    ->ignore($tag instanceof Tag ? $tag->getKey() : null)
                    ->where(fn ($query) => $query->where('account_id', resolve(CurrentTenant::class)->accountId)),
            ],
            'color' => ['sometimes', 'required', Rule::in(['neutral', 'primary', 'success', 'info', 'warning', 'error'])],
        ];
    }
}
