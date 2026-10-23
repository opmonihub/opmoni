<?php

namespace App\Http\Requests\Erp\Fiscal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreErpFiscalCertificateRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            'erp_establishment_id' => ['required', 'integer', 'exists:erp_establishments,id'],
            'certificate' => ['required_without:certificate_content', 'file', 'max:2048'],
            'certificate_content' => ['required_without:certificate', 'string', 'max:2796204'],
            'password' => ['present', 'string', 'max:255'],
        ];
    }
}
