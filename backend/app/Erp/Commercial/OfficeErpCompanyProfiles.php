<?php

namespace App\Erp\Commercial;

use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\ErpCompany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpCompanyProfiles
{
    private const CLIENT_FIELDS = ['name', 'trade_name', 'tax_regime', 'contact_person', 'email', 'phone', 'whatsapp',
        'street_type', 'street', 'address_number', 'address_complement', 'district', 'postal_code', 'city', 'state'];

    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpCompanies $companies,
        private readonly OfficeErpOperations $operations,
    ) {}

    public function show(OfficeErpExecutionContext $ctx, int $companyId): array
    {
        $overview = $this->companies->show($ctx, $companyId);
        $registration = $this->registration($ctx, $companyId);
        $client = Client::withoutGlobalScopes()->where('account_id', $ctx->accountId)->findOrFail($registration->client_id);
        $company = ErpCompany::query()->findOrFail($companyId);
        $profile = $client->only(self::CLIENT_FIELDS);
        $profile['tax_regime'] = $client->tax_regime?->value;
        $profile['ie'] = $overview['capabilities']['relationship'] === 'historical' ? null : $company->ie;

        return [...$overview, 'profile' => $profile, 'profile_version' => $this->version($client, $company)];
    }

    public function update(OfficeErpExecutionContext $ctx, int $companyId, array $input, string $key): array
    {
        $changes = $this->validate($input);
        $payload = ['company_id' => $companyId, 'expected_profile_version' => $input['expected_profile_version'], 'changes' => $changes];
        $outcome = $this->operations->execute($ctx, $key, 'company.profile.update', $payload,
            fn (string $action, array $stored): mixed => $this->authorizeOperation($ctx, $action, $stored),
            function () use ($ctx, $companyId, $input, $changes): array {
                $registration = $this->registration($ctx, $companyId);
                $client = Client::withoutGlobalScopes()->where('account_id', $ctx->accountId)->whereNull('deleted_at')
                    ->whereKey($registration->client_id)->lockForUpdate()->firstOrFail();
                $company = ErpCompany::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals($this->version($client, $company), $input['expected_profile_version']), 409, 'profile_changed');
                $client->forceFill(array_intersect_key($changes, array_flip(self::CLIENT_FIELDS)))->save();
                if (array_key_exists('ie', $changes)) {
                    $company->ie = $changes['ie'];
                }
                if (array_key_exists('name', $changes)) {
                    $company->razao_social = $client->name;
                }
                if (array_key_exists('name', $changes) || array_key_exists('trade_name', $changes)) {
                    $company->name = $client->trade_name ?: $client->name;
                }
                if ($company->isDirty()) {
                    $company->save();
                }

                return ['data' => $this->show($ctx, $companyId)];
            });

        return $outcome['data'];
    }

    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload): void
    {
        abort_unless($action === 'company.profile.update' && is_int($payload['company_id'] ?? null), 409);
        $registration = $this->registration($ctx, $payload['company_id']);
        $this->admission->assertCommand($ctx, $action);
        Client::withoutGlobalScopes()->where('account_id', $ctx->accountId)->whereNull('deleted_at')
            ->whereKey($registration->client_id)->lockForUpdate()->firstOrFail();
        ErpCompany::query()->whereKey($registration->erp_company_id)->lockForUpdate()->firstOrFail();
        $current = $this->companies->show($ctx, $payload['company_id']);
        abort_unless($current['capabilities']['can_edit_profile'], 403);
    }

    private function registration(OfficeErpExecutionContext $ctx, int $companyId): OfficeErpCompanyRegistration
    {
        return OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)->where('erp_company_id', $companyId)->firstOrFail();
    }

    private function version(Client $client, ErpCompany $company): string
    {
        return hash('sha256', json_encode([
            $client->id, $client->only(self::CLIENT_FIELDS), $client->getRawOriginal('updated_at'),
            $company->id, $company->name, $company->razao_social, $company->ie, $company->getRawOriginal('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }

    private function validate(array $input): array
    {
        $unknown = array_diff(array_keys($input), [...self::CLIENT_FIELDS, 'ie', 'expected_profile_version']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Campo não permitido.'));
        }
        $rules = ['expected_profile_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
        foreach (self::CLIENT_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:255'];
        }
        $rules['name'] = ['sometimes', 'required', 'string', 'min:10', 'max:255'];
        $rules['tax_regime'] = ['sometimes', 'required', Rule::in(['mei', 'simple_national', 'presumed_profit', 'actual_profit', 'other'])];
        $rules['email'] = ['sometimes', 'nullable', 'email', 'max:255'];
        $rules['phone'] = ['sometimes', 'nullable', 'string', 'max:20'];
        $rules['whatsapp'] = ['sometimes', 'nullable', 'string', 'max:30'];
        $rules['street_type'] = ['sometimes', 'nullable', 'string', 'max:40'];
        $rules['address_number'] = ['sometimes', 'nullable', 'string', 'max:30'];
        $rules['postal_code'] = ['sometimes', 'nullable', 'digits:8'];
        $rules['state'] = ['sometimes', 'nullable', 'string', 'regex:/\A[A-Z]{2}\z/'];
        $rules['ie'] = ['sometimes', 'nullable', 'string', 'max:20'];
        $data = Validator::make($input, $rules, [
            'required' => 'Preencha este campo.',
            'string' => 'Informe um texto válido.',
            'email' => 'Informe um e-mail válido.',
            'min' => 'Informe ao menos :min caracteres.',
            'max' => 'Informe no máximo :max caracteres.',
            'in' => 'Selecione uma opção válida.',
            'digits' => 'Informe exatamente :digits dígitos.',
            'regex' => 'Informe um valor no formato válido.',
            'name.min' => 'A razão social deve ter entre 10 e 255 caracteres.',
            'name.max' => 'A razão social deve ter entre 10 e 255 caracteres.',
            'postal_code.digits' => 'Informe o CEP com 8 dígitos.',
            'state.regex' => 'Informe a UF com duas letras maiúsculas.',
            'expected_profile_version.*' => 'Recarregue o cadastro antes de salvar.',
        ])->validate();
        unset($data['expected_profile_version']);
        if ($data === []) {
            throw ValidationException::withMessages(['profile' => 'Informe ao menos um campo para alterar.']);
        }

        return $data;
    }
}
