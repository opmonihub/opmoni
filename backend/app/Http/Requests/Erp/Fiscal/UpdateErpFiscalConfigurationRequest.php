<?php

namespace App\Http\Requests\Erp\Fiscal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateErpFiscalConfigurationRequest extends FormRequest
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
            'environment' => ['required', 'string', 'in:homologation,production'],
            'issuer' => ['required', 'array:tax_regime,state_registration,uf,address'],
            'issuer.cnpj' => ['prohibited'],
            'issuer.tax_regime' => ['required', 'integer', 'between:1,4'],
            'issuer.state_registration' => ['required', 'string', 'max:20'],
            'issuer.uf' => ['required', 'string', 'in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO'],
            'issuer.address' => ['required', 'array:street,number,district,city_code,city_name,zip_code,complement'],
            'issuer.address.street' => ['required', 'string', 'max:120'],
            'issuer.address.number' => ['required', 'string', 'max:20'],
            'issuer.address.district' => ['required', 'string', 'max:60'],
            'issuer.address.city_code' => ['required', 'string', 'size:7', 'regex:/^[0-9]{7}$/'],
            'issuer.address.city_name' => ['required', 'string', 'max:60'],
            'issuer.address.zip_code' => ['required', 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'issuer.address.complement' => ['sometimes', 'nullable', 'string', 'max:60'],
            'models' => ['sometimes', 'array:55,65,57,58'],
            'models.*' => ['array:series,next_number,operation_nature_id,transport,nfce_qr'],
            'models.*.series' => ['required', 'integer', 'between:0,999'],
            'models.*.next_number' => ['required', 'integer', 'between:1,999999999'],
            'models.*.operation_nature_id' => ['sometimes', 'nullable', 'integer'],
            'models.57.transport' => ['sometimes', 'array'],
            'models.58.transport' => ['sometimes', 'array'],
            'models.65.nfce_qr' => ['sometimes', 'array:version,mode,consultation_url'],
            'models.65.nfce_qr.version' => ['required_with:models.65.nfce_qr', 'in:3'],
            'models.65.nfce_qr.mode' => ['required_with:models.65.nfce_qr', 'in:online,offline'],
            'models.65.nfce_qr.consultation_url' => ['required_with:models.65.nfce_qr', 'url', 'max:512'],
        ];
    }
}
