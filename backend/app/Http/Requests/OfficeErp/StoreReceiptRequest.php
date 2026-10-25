<?php

namespace App\Http\Requests\OfficeErp;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReceiptRequest extends FormRequest
{
    /** @var list<string> */
    private const FIELDS = ['amount', 'received_on', 'reference', 'proof_id', 'allocations'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = [
            'amount' => ['required', 'string'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:191'],
            'proof_id' => ['sometimes', 'nullable', 'uuid'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['required', 'array:invoice_id,amount'],
            'allocations.*.invoice_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', 'string'],
        ];

        foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
