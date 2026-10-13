<?php

namespace App\Http\Requests\Tenant;

use App\Models\Client;
use App\Rules\ValidCnpj;
use App\Services\BrazilianTaxId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class LookupClientCnpjRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Client::class);
    }

    protected function prepareForValidation(): void
    {
        $taxId = $this->input('tax_id');

        if (is_string($taxId)) {
            $this->merge(['tax_id' => resolve(BrazilianTaxId::class)->normalize($taxId)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax_id' => ['required', 'size:14', new ValidCnpj],
        ];
    }
}
