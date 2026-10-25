<?php

namespace App\Http\Requests\OfficeErp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdjustInvoiceRequest extends FormRequest
{
    private const FIELDS = ['direction', 'amount', 'reason', 'corrects_adjustment_id'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = [
            'direction' => ['required', 'string', Rule::in(['debit', 'credit'])],
            'amount' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
            'corrects_adjustment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];

        foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
