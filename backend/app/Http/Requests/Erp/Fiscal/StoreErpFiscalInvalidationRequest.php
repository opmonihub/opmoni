<?php

namespace App\Http\Requests\Erp\Fiscal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreErpFiscalInvalidationRequest extends FormRequest
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
            'erp_establishment_id' => ['required', 'integer'],
            'model' => ['required', 'string', 'in:55,65'],
            'environment' => ['sometimes', 'in:homologation,production'],
            'year' => ['required', 'integer', 'between:2006,2099'],
            'series' => ['required', 'integer', 'between:0,999'],
            'number_from' => ['required', 'integer', 'between:1,999999999'],
            'number_to' => ['required', 'integer', 'gte:number_from', 'max:999999999'],
            'justification' => ['required', 'string', 'min:15', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }
}
