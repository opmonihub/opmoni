<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ClientPersonType;
use App\Enums\ClientStatus;
use App\Enums\TaxRegime;
use App\Models\Client;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use App\Services\BrazilianTaxId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Client::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Normalizado pelo serviço, não por dígitos: o CNPJ alfanumérico
            // (RFB IN 2.119/2022) tem letras nos doze primeiros caracteres, e
            // apagá-las aqui faria a validação enxergar um documento menor.
            'tax_id' => $this->taxId($this->input('tax_id')),
            'postal_code' => $this->digitsOrNull($this->input('postal_code')),
            'phone' => $this->digitsOrNull($this->input('phone')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->input('person_type') === ClientPersonType::Company->value;

        return [
            'person_type' => ['required', Rule::enum(ClientPersonType::class)],
            'tax_id' => ['required', $company ? 'size:14' : 'size:11', $company ? new ValidCnpj : new ValidCpf],
            'name' => [Rule::requiredIf(! $company), 'nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'tax_regime' => ['required', Rule::enum(TaxRegime::class)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'street_type' => [Rule::prohibitedIf($company), 'nullable', 'string', 'max:40'],
            'street' => [Rule::prohibitedIf($company), 'nullable', 'string', 'max:255'],
            'address_number' => [Rule::prohibitedIf($company), 'nullable', 'string', 'max:30'],
            'address_complement' => [Rule::prohibitedIf($company), 'nullable', 'string', 'max:255'],
            'district' => [Rule::prohibitedIf($company), 'nullable', 'string', 'max:255'],
            'postal_code' => [Rule::prohibitedIf($company), 'nullable', 'digits:8'],
            'city' => [Rule::prohibitedIf($company), 'nullable', 'string', 'max:255'],
            'state' => [Rule::prohibitedIf($company), 'nullable', 'string', 'size:2'],
        ];
    }

    /**
     * @return array<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->input('person_type') === ClientPersonType::Individual->value
                    && $this->input('tax_regime') !== TaxRegime::NotApplicable->value) {
                    $validator->errors()->add('tax_regime', 'Pessoa física aceita somente o regime não aplicável.');
                }
            },
        ];
    }

    private function taxId(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return resolve(BrazilianTaxId::class)->normalize($value);
    }

    private function digitsOrNull(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_replace('/\D+/', '', $value);
    }
}
