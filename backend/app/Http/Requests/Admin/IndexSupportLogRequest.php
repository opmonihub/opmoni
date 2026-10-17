<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexSupportLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'account_id' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991', 'exists:accounts,id'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
