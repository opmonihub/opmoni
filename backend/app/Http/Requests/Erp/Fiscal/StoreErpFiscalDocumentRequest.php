<?php

namespace App\Http\Requests\Erp\Fiscal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreErpFiscalDocumentRequest extends FormRequest
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
            'erp_fiscal_certificate_id' => ['sometimes', 'nullable', 'integer'],
            'erp_sale_id' => ['sometimes', 'nullable', 'integer'],
            'erp_sale_return_id' => ['sometimes', 'nullable', 'integer'],
            'source_document_id' => ['sometimes', 'nullable', 'integer'],
            'document_kind' => ['sometimes', 'string', 'in:posted_sale,return,complement,substitution,transport_service,transport_manifest'],
            'model' => ['required', 'string', 'in:55,65,57,58'],
            'environment' => ['sometimes', 'in:homologation,production'],
            'series' => ['sometimes', 'integer', 'between:0,999'],
            'number' => ['sometimes', 'integer', 'between:1,999999999'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'source' => ['required', 'array'],
            'source.identity' => ['sometimes', 'array'],
            'source.items' => ['sometimes', 'array', 'list', 'min:1', 'max:990'],
            'source.items.*' => ['array'],
            'source.items.*.tax' => ['sometimes', 'array'],
            'source.recipient' => ['sometimes', 'array'],
            'source.recipient.address' => ['sometimes', 'array'],
            'source.totals' => ['sometimes', 'array'],
            'source.payment' => ['sometimes', 'array', 'list'],
            'source.payment.*' => ['array'],
            'source.freight' => ['sometimes', 'array'],
            'source.transport_service' => ['sometimes', 'array'],
            'source.transport_manifest' => ['sometimes', 'array'],
            'source.totals.*' => ['string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'source.recipient.cnpj' => ['sometimes', 'string'],
            'source.items.*.source_line_id' => ['sometimes', 'integer'],
            'source.items.*.source_return_line_id' => ['sometimes', 'integer'],
            'source.items.*.erp_product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'source.items.*.code' => ['sometimes', 'string'],
            'source.items.*.ncm' => ['sometimes', 'string'],
            'source.items.*.cfop' => ['sometimes', 'string'],
            'source.items.*.unit' => ['sometimes', 'string'],
            'source.items.*.quantity' => ['sometimes', 'string', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/'],
            'source.items.*.unit_price' => ['sometimes', 'string', 'regex:/^\d{1,11}(?:\.\d{1,10})?$/'],
            'source.items.*.gross_total' => ['sometimes', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'source.items.*.tax.*' => ['numeric'],

        ];
    }
}
