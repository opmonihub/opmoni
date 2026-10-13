<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ClientPersonType;
use App\Enums\ClientStatus;
use App\Enums\TaxRegime;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && Gate::allows('update', $client);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'postal_code' => $this->digitsOrNull($this->input('postal_code')),
            'phone' => $this->digitsOrNull($this->input('phone')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $client = $this->route('client');
        $company = $client instanceof Client && $client->person_type === ClientPersonType::Company;

        return [
            'person_type' => ['prohibited'],
            'tax_id' => ['prohibited'],
            'name' => [Rule::prohibitedIf($company), 'sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(ClientStatus::class)],
            'tax_regime' => ['sometimes', 'required', Rule::enum(TaxRegime::class)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'street_type' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'string', 'max:40'],
            'street' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'string', 'max:255'],
            'address_number' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'string', 'max:30'],
            'address_complement' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'string', 'max:255'],
            'district' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'digits:8'],
            'city' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'string', 'max:255'],
            'state' => [Rule::prohibitedIf($company), 'sometimes', 'nullable', 'string', 'size:2'],
        ];
    }

    /**
     * @return array<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $client = $this->route('client');

                if (! $client instanceof Client || ! is_string($this->input('tax_regime'))) {
                    return;
                }

                if ($client->person_type === ClientPersonType::Individual
                    && $this->input('tax_regime') !== TaxRegime::NotApplicable->value) {
                    $validator->errors()->add('tax_regime', 'Pessoa física aceita somente o regime não aplicável.');
                }

                if ($client->person_type === ClientPersonType::Company
                    && $this->input('tax_regime') === TaxRegime::NotApplicable->value) {
                    $validator->errors()->add('tax_regime', 'Empresa não aceita o regime não aplicável.');
                }
            },
        ];
    }

    private function digitsOrNull(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_replace('/\D+/', '', $value);
    }
}
