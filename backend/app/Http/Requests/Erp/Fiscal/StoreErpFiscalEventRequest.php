<?php

namespace App\Http\Requests\Erp\Fiscal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreErpFiscalEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'erp_fiscal_document_id' => ['required', 'integer'],
            'event_type' => ['required', 'in:cancel,correction,substitution_cancel,close'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'justification' => ['sometimes', 'string', 'min:15', 'max:255'],
            'correction' => ['sometimes', 'string', 'min:15', 'max:1000'],
            'legal_scope_attested' => ['sometimes', 'boolean'],
            'corrections' => ['sometimes', 'array', 'list', 'min:1', 'max:20'],
            'replacement_document_id' => ['sometimes', 'integer'],
            'closed_on' => ['sometimes', 'date_format:Y-m-d'],
            'closing_uf' => ['sometimes', 'string', 'size:2'],
            'closing_city_code' => ['sometimes', 'string', 'regex:/^\d{7}$/'],
        ];
    }
}
