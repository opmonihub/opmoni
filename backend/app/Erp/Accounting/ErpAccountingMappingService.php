<?php

namespace App\Erp\Accounting;

use App\Enums\ClientStatus;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Support\AccessTransaction;
use App\Support\CurrentAccount;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpAccountingMappingService
{
    public function __construct(private AccountingMappingFence $fence) {}

    public function approve(CurrentAccount $context, int $delegationId, int $establishmentId, int $clientId, ?Closure $assertOwnership = null): ErpAccountingMapping
    {
        return DB::transaction(function () use ($context, $delegationId, $establishmentId, $clientId, $assertOwnership): ErpAccountingMapping {
            AccessTransaction::boundLockWaits();
            $this->assertOffice($context);
            $delegation = ErpOfficeDelegation::query()->whereKey($delegationId)->where('account_id', $context->account->id)->first();
            $this->related($delegation !== null, 'delegation_id');
            $company = $this->fence->acquire(ErpCompany::query()->whereKey($delegation->erp_company_id));
            $this->related($company !== null && $company->status === 'active', 'delegation_id');
            $delegation = ErpOfficeDelegation::query()->whereKey($delegationId)->lockForUpdate()->firstOrFail();
            $this->related($delegation->status === 'active' && (int) $delegation->account_id === (int) $context->account->id, 'delegation_id');
            $establishment = ErpEstablishment::query()->whereKey($establishmentId)->where('erp_company_id', $company->id)->lockForUpdate()->first();
            $this->related($establishment !== null && $establishment->status === 'active', 'erp_establishment_id');
            $history = ErpAccountingMapping::query()->where('delegation_id', $delegationId)->where('erp_establishment_id', $establishmentId)->orderBy('id')->lockForUpdate()->get();
            $client = Client::withoutGlobalScope('account')->whereKey($clientId)->where('account_id', $context->account->id)->lockForUpdate()->first();
            $this->related($client !== null && $client->status === ClientStatus::Active, 'client_id');
            $assertOwnership?->__invoke();
            $active = $history->firstWhere('status', 'active');
            if ($active && (int) $active->client_id === $clientId) {
                return $active;
            }
            if ($active) {
                $active->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $context->membership->user_id]);
            }
            $mapping = ErpAccountingMapping::query()->create([
                'erp_company_id' => $company->id, 'erp_establishment_id' => $establishmentId,
                'delegation_id' => $delegationId, 'account_id' => $context->account->id, 'client_id' => $clientId,
                'approved_by_user_id' => $context->membership->user_id, 'approved_at' => now(),
                'revision' => ((int) $history->max('revision')) + 1, 'status' => 'active',
            ]);
            $assertOwnership?->__invoke();

            return $mapping;
        });
    }

    public function revoke(CurrentAccount $context, int $mappingId, ?Closure $assertOwnership = null): ErpAccountingMapping
    {
        return DB::transaction(function () use ($context, $mappingId, $assertOwnership): ErpAccountingMapping {
            AccessTransaction::boundLockWaits();
            $this->assertOffice($context);
            $mapping = ErpAccountingMapping::query()->whereKey($mappingId)->where('account_id', $context->account->id)->firstOrFail();
            $this->fence->acquire(ErpCompany::query()->whereKey($mapping->erp_company_id));
            ErpOfficeDelegation::query()->whereKey($mapping->delegation_id)->lockForUpdate()->firstOrFail();
            $mapping = ErpAccountingMapping::query()->whereKey($mappingId)->lockForUpdate()->firstOrFail();
            $assertOwnership?->__invoke();
            if ($mapping->status === 'active') {
                $mapping->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $context->membership->user_id]);
            }
            $assertOwnership?->__invoke();

            return $mapping;
        });
    }

    private function assertOffice(CurrentAccount $context): void
    {
        $context->assertAdmin();
        $account = Account::query()->whereKey($context->account->id)->lockForUpdate()->firstOrFail();
        $membership = AccountMembership::query()->where('account_id', $account->id)->where('user_id', $context->membership->user_id)->lockForUpdate()->first();
        abort_unless($membership?->role === 'admin' && $account->status === 'active' && $account->subscription?->status === 'active', 403);
    }

    private function related(bool $valid, string $field): void
    {
        if (! $valid) {
            throw ValidationException::withMessages([$field => ['Vínculo indisponível para aprovação.']]);
        }
    }
}
