<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Http\Request;

final class OfficeErpMemberInvitationContinuation
{
    private const SESSION_KEY = 'office_erp.member_invitation_continuation';

    private const TTL_SECONDS = 900;

    /** @return array<string, mixed> */
    public function exchange(Request $request, string $token): array
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1, 422, 'invalid_invitation');
        $invitation = OfficeErpMemberInvitation::query()->where('token_hash', hash('sha256', $token))->first();
        if (! $invitation || $invitation->purpose !== 'member_access') {
            return ['state' => 'invalid'];
        }
        if ($invitation->status !== 'pending' || $invitation->expires_at === null || ! $invitation->expires_at->isFuture()) {
            return ['state' => $invitation->status === 'pending' ? 'expired' : 'invalid'];
        }
        $account = Account::query()->whereKey($invitation->account_id)->where('status', 'active')->first();
        if (! $account || ! $this->hasCurrentRelationship((int) $invitation->account_id, (int) $invitation->erp_company_id)) {
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

    /** @param array<string, mixed>|null $continuation */
    public static function restoreAfterAuthentication(Request $request, ?array $continuation): void
    {
        if ($continuation === null) {
            $request->session()->forget(self::SESSION_KEY);

            return;
        }
        if (self::signatureIsValid($continuation)) {
            $request->session()->put(self::SESSION_KEY, $continuation);

            return;
        }
        $request->session()->forget(self::SESSION_KEY);
    }

    /** @param array<string, mixed> $continuation @return array<string, mixed> */
    private function project(Request $request, array $continuation): array
    {
        $invitation = OfficeErpMemberInvitation::query()->whereKey($continuation['invitation_id'])
            ->where('account_id', $continuation['account_id'])->where('erp_company_id', $continuation['company_id'])
            ->first();
        if (! $invitation || $invitation->purpose !== 'member_access'
            || mb_strtolower(trim((string) $invitation->email)) !== $continuation['email']) {
            $this->clear($request);

            return ['state' => 'invalid'];
        }
        if ($invitation->status === 'pending' && ($invitation->expires_at === null || ! $invitation->expires_at->isFuture())) {
            $this->clear($request);

            return ['state' => 'expired'];
        }
        if (! in_array($invitation->status, ['pending', 'accepted'], true)) {
            $this->clear($request);

            return ['state' => 'invalid'];
        }
        $account = Account::query()->whereKey($invitation->account_id)->where('status', 'active')->first();
        $company = ErpCompany::query()->find($invitation->erp_company_id);
        if (! $account || ! $company || ! $this->hasCurrentRelationship((int) $invitation->account_id, (int) $invitation->erp_company_id)) {
            return ['state' => 'invalid'];
        }
        $issuerActive = $invitation->created_by_user_id !== null && $account->memberships()
            ->where('user_id', $invitation->created_by_user_id)->where('role', 'admin')->exists();
        if (! $issuerActive) {
            return ['state' => 'invalid'];
        }
        $authenticated = $request->user();
        if ($authenticated !== null && mb_strtolower(trim((string) $authenticated->email)) !== $invitation->email) {
            return ['state' => 'identity_mismatch', 'operation_key' => $continuation['operation_key']];
        }
        if ($invitation->status === 'accepted') {
            if ($authenticated === null || (int) $invitation->accepted_by_user_id !== (int) $authenticated->id) {
                return ['state' => 'login_required', 'operation_key' => $continuation['operation_key']];
            }

            return ['state' => 'accepted', 'purpose' => 'member_access', 'operation_key' => $continuation['operation_key'], 'data' => $this->invitationData($invitation, $company)];
        }

        $existingIdentity = $authenticated === null
            && User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$invitation->email])->exists();

        return [
            'state' => $authenticated === null ? ($existingIdentity ? 'login_required' : 'valid') : 'ready',
            'purpose' => 'member_access',
            'operation_key' => $continuation['operation_key'],
            'data' => $this->invitationData($invitation, $company),
        ];
    }

    /** @return array<string, mixed> */
    private function invitationData(OfficeErpMemberInvitation $invitation, ErpCompany $company): array
    {
        return [
            'invitation' => [
                'id' => (int) $invitation->id,
                'name' => (string) $invitation->name,
                'email' => (string) $invitation->email,
                'role' => (string) $invitation->role,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
            'company' => ['id' => (int) $company->id, 'name' => (string) $company->name],
        ];
    }

    private function hasCurrentRelationship(int $accountId, int $companyId): bool
    {
        $subscription = ErpSubscription::query()->where('erp_company_id', $companyId)->first();
        if ($subscription?->office_erp_contract_id === null) {
            return false;
        }

        return OfficeErpContract::query()->whereKey($subscription->office_erp_contract_id)
            ->where('account_id', $accountId)->where('erp_company_id', $companyId)
            ->whereIn('agreement_status', ['accepted', 'effective'])->exists();
    }

    /** @return array<string, mixed> */
    private function createPayload(OfficeErpMemberInvitation $invitation): array
    {
        $identity = hash('sha256', $invitation->id.':'.mb_strtolower(trim($invitation->email)));
        $payload = [
            'invitation_id' => (int) $invitation->id,
            'account_id' => (int) $invitation->account_id,
            'company_id' => (int) $invitation->erp_company_id,
            'email' => mb_strtolower(trim($invitation->email)),
            'invitation_identity' => $identity,
            'operation_key' => hash_hmac('sha256', 'office-erp-member-accept:'.$invitation->id.':'.$identity, (string) config('app.key')),
            'expires_at' => min(now()->addSeconds(self::TTL_SECONDS)->timestamp, $invitation->expires_at->timestamp),
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
        foreach (['invitation_id', 'account_id', 'company_id', 'email', 'invitation_identity', 'operation_key', 'expires_at'] as $key) {
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
}
