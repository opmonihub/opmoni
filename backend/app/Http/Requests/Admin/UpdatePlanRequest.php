<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $rules = [
            'slug' => ['missing'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'limits' => ['sometimes', 'array:users,clients,monitorings'],
            'limits.users' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
            'limits.clients' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
            'limits.monitorings' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
        ];
        foreach (array_diff(array_keys($this->all()), ['name', 'limits', 'slug']) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    /** @return array{name?: string, limits?: array{users: ?int, clients: ?int, monitorings: ?int}} */
    public function planAttributes(): array
    {
        $data = $this->validated();
        if (array_key_exists('limits', $data)) {
            $data['limits'] = array_replace(['users' => null, 'clients' => null, 'monitorings' => null], $data['limits']);
        }

        return $data;
    }
}
