<?php

namespace App\Services;

use App\Enums\ClientPersonType;
use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientManager
{
    /**
     * @var list<string>
     */
    private const OFFICIAL_FIELDS = [
        'name',
        'trade_name',
        'registration_status',
        'registration_status_date',
        'opened_at',
        'company_size',
        'legal_nature',
        'primary_activity_code',
        'primary_activity_description',
        'street_type',
        'street',
        'address_number',
        'address_complement',
        'district',
        'postal_code',
        'city',
        'state',
        'phone',
        'email',
    ];

    public function __construct(private CnpjWsLookup $lookup, private ClientCertificateVault $certificateVault) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Account $account, array $data): Client
    {
        PlanLimits::assertCanCreate($account, 'clients');

        // Outbound lookup BEFORE opening the transaction so no row lock is held
        // while waiting on the provider; failures leave no partial write behind.
        $companyPayload = $data['person_type'] === ClientPersonType::Company->value
            ? $this->lookupCompany($data['tax_id'])
            : null;

        return DB::transaction(function () use ($account, $data, $companyPayload): Client {
            $existing = Client::withoutGlobalScopes()
                ->withTrashed()
                ->where('account_id', $account->getKey())
                ->where('tax_id', $data['tax_id'])
                ->lockForUpdate()
                ->first();

            $attributes = $this->attributesFor($data, $companyPayload);

            if ($existing !== null && ! $existing->trashed()) {
                throw ValidationException::withMessages(['tax_id' => 'Este CPF/CNPJ já está cadastrado nesta carteira.']);
            }

            if ($existing !== null) {
                $existing->restore();
                $existing->fill($attributes)->save();

                return $existing->refresh();
            }

            return $account->clients()->create($attributes);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Client $client, array $data): Client
    {
        $allowed = $client->person_type === ClientPersonType::Company
            ? ['status', 'tax_regime', 'email', 'phone']
            : ['name', 'status', 'email', 'phone', 'street_type', 'street', 'address_number',
                'address_complement', 'district', 'postal_code', 'city', 'state'];

        $filtered = Arr::only($data, $allowed);

        if ($client->person_type === ClientPersonType::Company && array_key_exists('tax_regime', $filtered)) {
            // Live lookup enforces the same rule as create (MEI=>mei,
            // Simples=>simple_national, else only presumed_profit|actual_profit|other).
            // Official registration fields are NOT overwritten here, only the regime
            // is validated/coerced.
            $payload = $this->lookupCompany($client->tax_id);
            $filtered['tax_regime'] = $payload !== null
                ? $this->resolveCompanyRegime($payload, $filtered['tax_regime'])
                : $this->typedCompanyRegime($filtered['tax_regime']);
        }

        $client->fill($filtered)->save();

        return $client->refresh();
    }

    public function delete(Client $client): void
    {
        DB::transaction(function () use ($client): void {
            $locked = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $this->certificateVault->remove($locked);
            $locked->delete();
        });
    }

    /**
     * @return array{current: array<string, mixed>, incoming: array<string, mixed>, changes: array<string, array{from: mixed, to: mixed}>}
     */
    public function previewCompany(Client $client): array
    {
        $this->assertCompany($client);

        $incoming = $this->officialAttributes($this->lookup->lookup($client->tax_id));
        $current = $this->officialAttributes($client->attributesToArray());

        return [
            'current' => $current,
            'incoming' => $incoming,
            'changes' => $this->diff($current, $incoming),
        ];
    }

    public function refreshCompany(Client $client): Client
    {
        $this->assertCompany($client);

        $payload = $this->lookup->lookup($client->tax_id);

        return DB::transaction(function () use ($client, $payload): Client {
            $client->fill($this->officialAttributes($payload));
            $client->forceFill([
                'tax_regime' => $this->resolveCompanyRegime($payload, $client->tax_regime?->value),
                'source_updated_at' => $payload['source_updated_at'],
                'looked_up_at' => $payload['looked_up_at'],
            ])->save();

            return $client->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload  Live lookup result (fetched before the transaction).
     * @return array<string, mixed>
     */
    private function companyAttributes(array $data, array $payload): array
    {
        $attributes = $this->officialAttributes($payload);

        if (is_string($data['email'] ?? null) && $data['email'] !== '') {
            $attributes['email'] = $data['email'];
        }

        if (is_string($data['phone'] ?? null) && $data['phone'] !== '') {
            $attributes['phone'] = $data['phone'];
        }

        return array_merge($attributes, [
            'person_type' => ClientPersonType::Company->value,
            'tax_id' => $payload['tax_id'],
            'status' => $data['status'],
            'tax_regime' => $this->resolveCompanyRegime($payload, $data['tax_regime'] ?? null),
            'source_updated_at' => $payload['source_updated_at'],
            'looked_up_at' => $payload['looked_up_at'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $companyPayload
     * @return array<string, mixed>
     */
    private function attributesFor(array $data, ?array $companyPayload): array
    {
        if ($companyPayload !== null) {
            return $this->companyAttributes($data, $companyPayload);
        }

        return $data['person_type'] === ClientPersonType::Company->value
            ? $this->typedCompanyAttributes($data)
            : $this->individualAttributes($data);
    }

    /**
     * Empresa que a consulta pública não conhece: entra o que o usuário digitou.
     *
     * Os campos oficiais ficam de fora de propósito — um cliente novo fica sem dado
     * da Receita até a primeira consulta bem-sucedida, e um cliente apagado e
     * recadastrado por aqui conserva os dados oficiais que já tinha, com o
     * `looked_up_at` de quando foram lidos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function typedCompanyAttributes(array $data): array
    {
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Informe a razão social do cliente.']);
        }

        return [
            'person_type' => ClientPersonType::Company->value,
            'tax_id' => $data['tax_id'],
            'name' => $name,
            'trade_name' => null,
            'status' => $data['status'],
            'tax_regime' => $this->typedCompanyRegime($data['tax_regime'] ?? null),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ];
    }

    /**
     * Sem a Receita não há como prometer MEI ou Simples, então vale o regime escolhido
     * desde que a empresa o aceite.
     */
    private function typedCompanyRegime(mixed $requested): string
    {
        if (in_array($requested, [TaxRegime::PresumedProfit->value, TaxRegime::ActualProfit->value, TaxRegime::Other->value], true)) {
            return $requested;
        }

        throw ValidationException::withMessages(['tax_regime' => 'Empresa não aceita o regime não aplicável.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function individualAttributes(array $data): array
    {
        return [
            'person_type' => ClientPersonType::Individual->value,
            'tax_id' => $data['tax_id'],
            'name' => $data['name'],
            'trade_name' => null,
            'status' => $data['status'],
            'tax_regime' => TaxRegime::NotApplicable->value,
            'street_type' => $data['street_type'] ?? null,
            'street' => $data['street'] ?? null,
            'address_number' => $data['address_number'] ?? null,
            'address_complement' => $data['address_complement'] ?? null,
            'district' => $data['district'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function officialAttributes(array $payload): array
    {
        $official = Arr::only($payload, self::OFFICIAL_FIELDS);

        foreach (['registration_status_date', 'opened_at'] as $field) {
            if (isset($official[$field]) && $official[$field] !== null && $official[$field] !== '') {
                $official[$field] = substr((string) $official[$field], 0, 10);
            } else {
                $official[$field] = $official[$field] ?? null;
            }
        }

        return $official;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $current, array $incoming): array
    {
        $changes = [];

        foreach ($incoming as $field => $to) {
            $from = $current[$field] ?? null;

            if ((string) ($from ?? '') !== (string) ($to ?? '')) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCompanyRegime(array $payload, ?string $requested): string
    {
        if (($payload['mei'] ?? false) === true) {
            return TaxRegime::Mei->value;
        }

        if (($payload['simple_national'] ?? false) === true) {
            return TaxRegime::SimpleNational->value;
        }

        if (in_array($requested, [TaxRegime::PresumedProfit->value, TaxRegime::ActualProfit->value, TaxRegime::Other->value], true)) {
            return $requested;
        }

        throw ValidationException::withMessages(['tax_regime' => 'Regime tributário incompatível com os dados da Receita.']);
    }

    /**
     * A consulta à Receita é enriquecimento, não requisito: o cadastro não pode
     * depender de uma fonte pública que não conhece documento alfanumérico
     * (RFB IN 2.119/2022) nem todo CNPJ recém-aberto.
     *
     * Só o 404 vira cadastro manual. Indisponibilidade (503), limite do provedor
     * (429) e documento recusado (422) continuam errando a requisição: um cliente
     * sem os dados oficiais por causa de uma queda de rede é efeito colateral de
     * quem não deu origem à queda, e o operador precisa ver a falha em vez de um
     * cadastro mais pobre do que a empresa é de verdade.
     *
     * @return array<string, mixed>|null `null` quando a fonte não conhece o documento.
     */
    private function lookupCompany(string $taxId): ?array
    {
        try {
            return $this->lookup->lookup($taxId);
        } catch (CnpjLookupException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    private function assertCompany(Client $client): void
    {
        if ($client->person_type !== ClientPersonType::Company) {
            throw ValidationException::withMessages(['tax_id' => 'A atualização via CNPJ está disponível apenas para empresas.']);
        }
    }
}
