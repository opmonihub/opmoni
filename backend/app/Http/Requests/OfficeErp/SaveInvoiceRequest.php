<?php

namespace App\Http\Requests\OfficeErp;

use Illuminate\Foundation\Http\FormRequest;

final class SaveInvoiceRequest extends FormRequest
{
    private const FIELDS = ['contract_id', 'items', 'period_start', 'period_end', 'due_on'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $rules = [
            'contract_id' => [$creating ? 'required' : 'missing', 'integer', 'min:1'],
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:description,amount,discount'],
            'items.*.description' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/u'],
            'items.*.amount' => ['required', 'string'],
            'items.*.discount' => ['required', 'string'],
            'period_start' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'period_end' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'due_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
        ];

        foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function invoiceInput(): array
    {
        $data = $this->validated();
        unset($data['contract_id']);

        return $data;
    }
}
