<?php

namespace App\Erp\Fiscal;

use App\Erp\ErpCommercialGate;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpReleaseGate;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Crypt;

final class ErpFiscalAttemptAuthorization
{
    public function allowsDispatch(ErpFiscalAttempt $attempt): bool
    {
        $company = $attempt->company()->lockForUpdate()->first();
        $establishment = $attempt->establishment()->lockForUpdate()->first();
        $actor = $attempt->actor()->first();
        if (! $company || $company->status !== 'active' || ! $establishment || $establishment->status !== 'active'
            || (int) $establishment->erp_company_id !== (int) $company->id || ! $actor
            || ! app(ErpCommercialGate::class)->allowsOperatingWrites($company)
            || ! filter_var(config('erp.operating_writes_enabled', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $module = match ($attempt->model) {
            '57' => 'cte', '58' => 'mdfe', default => null
        };
        if ($module !== null && ! app(ErpCommercialGate::class)->moduleEnabled($company, $module)) {
            return false;
        }
        $snapshot = $this->sourceSnapshot($attempt);
        $access = $snapshot['request']['authorization'] ?? $snapshot['authorization'] ?? [];
        $permission = $this->permissionFor($attempt->action);
        if ($permission === null) {
            return false;
        }
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $actor->id)->first();
        try {
            if (($access['access_kind'] ?? 'direct') === 'direct') {
                if (! $membership) {
                    return false;
                }
                $context = new CurrentCompany($company, 'direct', $membership, null, null);
            } else {
                $delegation = ErpOfficeDelegation::query()->whereKey((int) ($access['delegation_id'] ?? 0))
                    ->where('erp_company_id', $company->id)->where('account_id', (int) ($access['office_account_id'] ?? 0))->where('status', 'active')->first();
                $officeMembership = AccountMembership::query()->where('account_id', (int) ($access['office_account_id'] ?? 0))->where('user_id', $actor->id)->first();
                if (! $delegation || ! $officeMembership || ! Account::query()->whereKey($delegation->account_id)->where('status', 'active')->exists()) {
                    return false;
                }
                $context = new CurrentCompany($company, 'delegated', null, $delegation, (int) $delegation->account_id, $officeMembership->role);
            }
            $context->assertPermission($permission, true);
            app(ErpEstablishmentAccess::class)->assertRelated($context, (int) $establishment->id);

            app(ErpReleaseGate::class)->assertReleased((int) $company->id, (int) $actor->id);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function sourceSnapshot(ErpFiscalAttempt $attempt): array
    {
        if (! is_string($attempt->encrypted_source_snapshot) || $attempt->encrypted_source_snapshot === '') {
            return [];
        }
        try {
            $plain = Crypt::decryptString($attempt->encrypted_source_snapshot);
            if (! hash_equals((string) $attempt->request_sha256, hash('sha256', $plain))) {
                return [];
            }
            $decoded = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function permissionFor(string $action): ?string
    {
        return match ($action) {
            'document:draft' => 'erp.fiscal.documents.store',
            'document:reconcile' => 'erp.fiscal.documents.reconcile-contingency',
            'document:queue' => 'erp.fiscal.documents.queue',
            'document:transmit' => 'erp.fiscal.documents.transmit',
            'document:consult' => 'erp.fiscal.documents.consult',
            'document:contingency' => 'erp.fiscal.documents.contingency',
            'event:submit' => 'erp.fiscal.events.submit',
            'invalidation:submit' => 'erp.fiscal.invalidations.submit',
            default => null,
        };
    }
}
