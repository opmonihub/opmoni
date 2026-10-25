<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Http\Request;

final class OfficeErpInvitationContinuation
{
    private const SESSION_KEY = 'office_erp.owner_continuation';

    private const TTL_SECONDS = 900;

    /** @return array<string, mixed> */
    public function exchange(Request $request, string $token): array
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1, 422, 'invalid_invitation');
        $invitation = OfficeErpOwnerInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();
        if (! $invitation) {
            return ['state' => 'invalid'];
        }
        if ($invitation->expires_at === null || ! $invitation->expires_at->isFuture() || $invitation->status === 'expired') {
            return ['state' => 'expired'];
        }
        if (! in_array($invitation->status, ['pending', 'consumed'], true)) {
            return ['state' => 'invalid'];
        }

        $account = Account::query()->find($invitation->account_id);
        if (! $account || $account->status !== 'active') {
            return ['state' => 'invalid'];
        }

        $continuation = $this->createPayload($invitation);
        $request->session()->put(self::SESSION_KEY, $continuation);

        return $this->project($request, $continuation);
    }

    /** @return array<string, mixed> */
    public function current(Request $request): array
    {
        $continuation = self::verifiedPayload($request);
        if ($continuation === null) {
            return ['state' => 'missing'];
        }
        if ((int) $continuation['expires_at'] <= now()->timestamp) {
            $this->clear($request);

            return ['state' => 'expired'];
        }

        return $this->project($request, $continuation);
    }

    /** @return array<string, mixed> */
    public function verifiedContinuation(Request $request): array
    {
        $continuation = self::verifiedPayload($request);
        if ($continuation === null) {
            abort(response()->json(['code' => 'invitation_continuation_required', 'message' => 'Abra novamente o convite para continuar.'], 410));
        }
        if ((int) $continuation['expires_at'] <= now()->timestamp) {
            $this->clear($request);
            abort(response()->json(['code' => 'invitation_continuation_expired', 'message' => 'Este acesso expirou. Abra novamente o convite.'], 410));
        }

        return $continuation;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /** @return array<string, mixed>|null */
    public static function preservableForAuthentication(Request $request): ?array
    {
        $continuation = self::verifiedPayload($request);
        if ($continuation === null || (int) $continuation['expires_at'] <= now()->timestamp) {
            return null;
        }

        return $continuation;
    }

    /** @param array<string, mixed> $continuation */
    public static function restoreAfterAuthentication(Request $request, ?array $continuation): void
    {
        if ($continuation === null || self::signatureIsValid($continuation)) {
            if ($continuation === null) {
                $request->session()->forget(self::SESSION_KEY);

                return;
            }
            $request->session()->put(self::SESSION_KEY, $continuation);

            return;
        }

        $request->session()->forget(self::SESSION_KEY);
    }

    /** @param array<string, mixed> $continuation */
    private function project(Request $request, array $continuation): array
    {
        $invitation = OfficeErpOwnerInvitation::query()->find($continuation['invitation_id']);
        if (! $invitation || (int) $invitation->account_id !== (int) $continuation['account_id']) {
            $this->clear($request);

            return ['state' => 'invalid'];
        }
        if ($invitation->expires_at === null || ! $invitation->expires_at->isFuture() || $invitation->status === 'expired') {
            $this->clear($request);

            return ['state' => 'expired'];
        }
        if (! in_array($invitation->status, ['pending', 'consumed'], true)) {
            $this->clear($request);

            return ['state' => 'invalid'];
        }
        if ($invitation->owner_email !== $continuation['owner_email']) {
            $this->clear($request);

            return ['state' => 'invalid'];
        }

        $account = Account::query()->find($invitation->account_id);
        if (! $account) {
            return ['state' => 'invalid'];
        }

        $authenticated = $request->user();
        if ($authenticated && mb_strtolower(trim($authenticated->email)) !== $invitation->owner_email) {
            return ['state' => 'identity_mismatch', 'operation_key' => $continuation['operation_key']];
        }
        if ($invitation->status === 'consumed' && ! $authenticated) {
            return ['state' => 'login_required', 'operation_key' => $continuation['operation_key']];
        }

        $data = $this->invitationData($account, $invitation, $authenticated);

        return [
            'state' => $invitation->status === 'consumed' ? 'accepted' : ($authenticated ? 'ready' : 'valid'),
            'operation_key' => $continuation['operation_key'],
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function invitationData(Account $account, OfficeErpOwnerInvitation $invitation, ?User $user): array
    {
        return app(CurrentTenant::class)->run((int) $account->id, function () use ($account, $invitation, $user): array {
            $contract = OfficeErpContract::query()->with(['currentRevision.salesPlanRevision', 'client', 'company'])->find($invitation->contract_id);
            if (! $contract || (int) $contract->account_id !== (int) $account->id) {
                return [];
            }
            $revision = $contract->currentRevision;
            $client = $contract->client;
            if (! $revision || ! $client) {
                return [];
            }

            $companyOptions = [];
            if ($user !== null) {
                $companyOptions = ErpCompany::query()
                    ->join('erp_company_memberships', 'erp_companies.id', '=', 'erp_company_memberships.erp_company_id')
                    ->where('erp_company_memberships.user_id', $user->id)
                    ->where('erp_company_memberships.role', 'owner')
                    ->where('erp_companies.cnpj', $client->tax_id)
                    ->orderBy('erp_companies.id')
                    ->get(['erp_companies.id', 'erp_companies.name', 'erp_companies.cnpj'])
                    ->map(static fn (ErpCompany $company): array => [
                        'id' => (int) $company->id,
                        'name' => (string) $company->name,
                        'cnpj' => (string) $company->cnpj,
                    ])->all();
            }

            return [
                'invitation' => [
                    'id' => (int) $invitation->id,
                    'owner_name' => $invitation->owner_name,
                    'owner_email' => $invitation->owner_email,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                ],
                'seller' => $invitation->seller_snapshot,
                'client' => [
                    'name' => $client->name,
                    'trade_name' => $client->trade_name,
                    'tax_id' => $client->tax_id,
                ],
                'offer' => [
                    'name' => $revision->salesPlanRevision?->name ?? $contract->salesPlan?->name,
                    'price' => (string) $revision->price,
                    'currency' => $revision->currency,
                    'interval_months' => (int) $revision->interval_months,
                    'due_in_days' => (int) $revision->due_in_days,
                    'module_keys' => $revision->module_keys,
                ],
                'service' => [
                    'billing_timezone' => $contract->billing_timezone,
                ],
                'companies' => $companyOptions,
                'accepted_company' => $contract->erp_company_id === null ? null : [
                    'id' => (int) $contract->company->id,
                    'name' => (string) $contract->company->name,
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    private function createPayload(OfficeErpOwnerInvitation $invitation): array
    {
        $identity = hash('sha256', $invitation->id.':'.mb_strtolower(trim($invitation->owner_email)));
        $payload = [
            'invitation_id' => (int) $invitation->id,
            'account_id' => (int) $invitation->account_id,
            'contract_id' => (int) $invitation->contract_id,
            'owner_email' => $invitation->owner_email,
            'owner_name' => $invitation->owner_name,
            'owner_identity' => $identity,
            'operation_key' => hash_hmac('sha256', 'office-erp-owner-acceptance:'.$invitation->id.':'.$identity, $this->applicationKey()),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ];
        $payload['signature'] = self::sign($payload);

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private static function verifiedPayload(Request $request): ?array
    {
        $payload = $request->session()->get(self::SESSION_KEY);
        if (! is_array($payload) || ! self::signatureIsValid($payload)) {
            return null;
        }
        foreach (['invitation_id', 'account_id', 'contract_id', 'owner_email', 'owner_name', 'owner_identity', 'operation_key', 'expires_at'] as $key) {
            if (! array_key_exists($key, $payload)) {
                return null;
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function signatureIsValid(array $payload): bool
    {
        $signature = $payload['signature'] ?? null;
        if (! is_string($signature)) {
            return false;
        }
        unset($payload['signature']);

        return hash_equals(self::sign($payload), $signature);
    }

    /** @param array<string, mixed> $payload */
    private static function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }

    private function applicationKey(): string
    {
        return (string) config('app.key');
    }
}
