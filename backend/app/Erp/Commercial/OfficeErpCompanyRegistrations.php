<?php

namespace App\Erp\Commercial;

use App\Erp\ErpCompanyProvisioning;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\ErpCompany;
use App\Rules\ValidCnpj;
use App\Services\BrazilianTaxId;
use App\Services\ClientManager;
use App\Services\PlanLimits;
use App\Support\AccessTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpCompanyRegistrations
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
        private readonly ClientManager $clients,
        private readonly ErpCompanyProvisioning $companies,
        private readonly OfficeErpCompanies $portfolio,
    ) {}

    public function register(OfficeErpExecutionContext $ctx, array $input, string $key): array
    {
        $data = $this->validate($input);
        $payload = ['command' => 'company.register'];
        if (isset($data['client_ids'])) {
            $payload['client_ids'] = $data['client_ids'];
        } else {
            $payload['new_client_fingerprint'] = hash('sha256', json_encode($data['new_client'], JSON_THROW_ON_ERROR));
        }
        $existing = $this->operation($ctx, $key);
        $prepared = null;
        $selected = collect();
        if ($existing === null) {
            $this->admission->assertCommand($ctx, 'company.register');
            if (isset($data['new_client'])) {
                PlanLimits::assertCanCreate($ctx->account, 'clients');
                $prepared = $this->clients->prepareCompanyRegistration([
                    ...$data['new_client'], 'person_type' => 'company', 'status' => 'active',
                ]);
            } else {
                $selected = $this->selectedClients($ctx, $data['client_ids']);
            }
        }
        $cnpjs = isset($data['new_client']) ? [$data['new_client']['tax_id']] : $selected->pluck('tax_id')->all();
        sort($cnpjs, SORT_STRING);

        return DB::transaction(function () use ($ctx, $key, $data, $payload, $prepared, $cnpjs): array {
            AccessTransaction::boundLockWaits();
            foreach (array_unique($cnpjs) as $cnpj) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['erp-company:'.$cnpj]);
                }
            }
            $outcome = $this->operations->execute($ctx, $key, 'company.register', $payload,
                fn (string $action, array $stored): mixed => $this->authorizeOperation($ctx, $action, $stored, $key),
                function () use ($ctx, $data, $prepared): array {
                    [$account] = $this->admission->lockCommercialAggregate($ctx);
                    $clients = isset($data['client_ids'])
                        ? $this->selectedClients($ctx, $data['client_ids'], true)
                        : collect([$this->clients->createPreparedCompany($account, $prepared)]);
                    $result = ['created' => [], 'unchanged' => []];
                    foreach ($clients as $client) {
                        $provisioned = $this->provisionClientLocked($ctx, $client);
                        $result[$provisioned['created'] ? 'created' : 'unchanged'][] = $this->receipt($provisioned['registration']);
                    }

                    return ['data' => $result];
                });

            return $outcome['data'];
        }, 3);
    }

    /**
     * The caller holds normalized CNPJ and office aggregate locks and owns the transaction.
     *
     * @return array{registration:OfficeErpCompanyRegistration,created:bool}
     */
    public function provisionClientLocked(OfficeErpExecutionContext $ctx, Client $client, bool $allowRegistered = true): array
    {
        $ctx->assertVerified();
        abort_unless($ctx->actorKind === 'office_admin' && DB::transactionLevel() > 0, 403);
        abort_unless((int) $client->account_id === $ctx->accountId && $client->person_type->value === 'company'
            && $client->status->value === 'active' && $client->deleted_at === null, 404);
        $registration = OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)
            ->where('client_id', $client->id)->lockForUpdate()->first();
        if ($registration !== null) {
            abort_unless($allowRegistered, 409, 'company_association_required');
            $this->assertCurrentRegistration($ctx, $registration);

            return ['registration' => $registration, 'created' => false];
        }
        abort_if(ErpCompany::query()->where('cnpj', $client->tax_id)->lockForUpdate()->exists(), 409, 'company_association_required');
        $company = $this->companies->createUnclaimedCompany(['name' => $client->name, 'cnpj' => $client->tax_id]);
        $registration = OfficeErpRecords::persist($ctx, new OfficeErpCompanyRegistration([
            'account_id' => $ctx->accountId, 'client_id' => $client->id, 'erp_company_id' => $company->id,
            'created_by_user_id' => $ctx->actor?->getKey(), 'registered_at' => now(),
        ]));

        return ['registration' => $registration, 'created' => true];
    }

    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, string $key): void
    {
        abort_unless($action === 'company.register' && ($payload['command'] ?? null) === $action, 409);
        abort_unless(isset($payload['client_ids']) xor isset($payload['new_client_fingerprint']), 409);
        $existing = $this->operation($ctx, $key);
        if ($existing !== null && $existing->outcome !== null) {
            $this->admission->capabilities($ctx);
            $outcome = $existing->outcome['data'] ?? null;
            abort_unless(is_array($outcome) && is_array($outcome['created'] ?? null) && is_array($outcome['unchanged'] ?? null), 409);
            foreach ([...$outcome['created'], ...$outcome['unchanged']] as $entry) {
                $registration = OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)->whereKey($entry['registration_id'])
                    ->where('client_id', $entry['client_id'])->where('erp_company_id', $entry['company_id'])->firstOrFail();
                $this->assertCurrentRegistration($ctx, $registration);
            }

            return;
        }
        $this->admission->assertCommand($ctx, $action);
    }

    private function assertCurrentRegistration(OfficeErpExecutionContext $ctx, OfficeErpCompanyRegistration $registration): void
    {
        $this->admission->capabilities($ctx);
        ErpCompany::query()->whereKey($registration->erp_company_id)->lockForUpdate()->firstOrFail();
        $current = $this->portfolio->show($ctx, (int) $registration->erp_company_id);
        abort_unless(in_array($current['capabilities']['relationship'], ['current', 'unassigned'], true), 403);
    }

    private function operation(OfficeErpExecutionContext $ctx, string $key): ?OfficeErpCommercialOperation
    {
        return OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)->where('action', 'company.register')->first();
    }

    private function selectedClients(OfficeErpExecutionContext $ctx, array $ids, bool $lock = false): Collection
    {
        $query = Client::withoutGlobalScopes()->where('account_id', $ctx->accountId)->whereNull('deleted_at')
            ->where('person_type', 'company')->where('status', 'active')->whereIn('id', $ids)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $clients = $query->get();
        if ($clients->count() !== count($ids)) {
            throw ValidationException::withMessages(['client_ids' => 'Selecione apenas clientes ativos da carteira deste escritório.']);
        }

        return $clients;
    }

    private function receipt(OfficeErpCompanyRegistration $registration): array
    {
        return ['registration_id' => (int) $registration->id, 'client_id' => (int) $registration->client_id, 'company_id' => (int) $registration->erp_company_id];
    }

    private function validate(array $input): array
    {
        $unknown = array_diff(array_keys($input), ['client_ids', 'new_client']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Campo não permitido.'));
        }
        $data = Validator::make($input, [
            'client_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991', 'distinct:strict'],
            'new_client' => ['sometimes', 'array:tax_id,tax_regime,name,email,phone'],
            'new_client.tax_id' => ['required_with:new_client', 'string', 'size:14', new ValidCnpj],
            'new_client.tax_regime' => ['required_with:new_client', Rule::in(['mei', 'simple_national', 'presumed_profit', 'actual_profit', 'other'])],
            'new_client.name' => ['nullable', 'string', 'max:255'],
            'new_client.email' => ['nullable', 'email', 'max:255'],
            'new_client.phone' => ['nullable', 'string', 'max:20'],
        ])->validate();
        if (isset($data['client_ids']) === isset($data['new_client'])) {
            throw ValidationException::withMessages(['client_ids' => 'Selecione clientes da carteira ou informe um novo cliente.']);
        }
        if (isset($data['client_ids'])) {
            sort($data['client_ids'], SORT_NUMERIC);
        } else {
            $data['new_client']['tax_id'] = app(BrazilianTaxId::class)->normalize($data['new_client']['tax_id']);
            ksort($data['new_client']);
        }

        return $data;
    }
}
