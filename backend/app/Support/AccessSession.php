<?php

namespace App\Support;

use App\Erp\Commercial\OfficeErpInvitationContinuation;
use App\Erp\Commercial\OfficeErpMemberInvitationContinuation;
use App\Erp\Commercial\OfficeErpPasswordRecovery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AccessSession
{
    public static function revoke(Request $request): void
    {
        $ttl = max(1, (int) $request->session()->get('access_deadline', time() + 43200) - time());
        Cache::put('revoked:'.hash('sha256', $request->session()->getId()), true, $ttl);
    }

    public static function authenticate(Request $request, User $user, ?int $accountId, int $capturedAuthRevision): void
    {
        self::assertOwnership($request);
        $invitationContinuation = OfficeErpInvitationContinuation::preservableForAuthentication($request);
        $memberInvitationContinuation = OfficeErpMemberInvitationContinuation::preservableForAuthentication($request);
        $passwordRecoveryContinuation = OfficeErpPasswordRecovery::preservableForAuthentication($request);
        self::revoke($request);
        Auth::guard('web')->login($user);
        $request->session()->regenerate(true);
        OfficeErpInvitationContinuation::restoreAfterAuthentication($request, $invitationContinuation);
        OfficeErpMemberInvitationContinuation::restoreAfterAuthentication($request, $memberInvitationContinuation);
        OfficeErpPasswordRecovery::restoreAfterAuthentication($request, $passwordRecoveryContinuation);
        ErpSession::clearCompanySelection($request);
        $request->session()->put([
            'access_deadline' => time() + 43200, 'access_authenticated' => true,
            'current_account_id' => $accountId, 'access_auth_revision' => $capturedAuthRevision,
        ]);
    }

    /** @return array{owner_invitation:array<string, mixed>|null,member_invitation:array<string, mixed>|null,password_recovery:array<string, mixed>|null} */
    public static function preservableContinuationsForLogout(Request $request): array
    {
        return [
            'owner_invitation' => OfficeErpInvitationContinuation::preservableForAuthentication($request),
            'member_invitation' => OfficeErpMemberInvitationContinuation::preservableForAuthentication($request),
            'password_recovery' => OfficeErpPasswordRecovery::preservableForLogout($request),
        ];
    }

    /** @param array<string, mixed> $continuations */
    public static function restoreContinuationsAfterLogout(Request $request, array $continuations): void
    {
        OfficeErpInvitationContinuation::restoreAfterAuthentication($request, $continuations['owner_invitation'] ?? null);
        OfficeErpMemberInvitationContinuation::restoreAfterAuthentication($request, $continuations['member_invitation'] ?? null);
        OfficeErpPasswordRecovery::restoreAfterLogout($request, $continuations['password_recovery'] ?? null);
    }

    /** @return array<string, mixed>|null */
    public static function preservableCompletedRecoveryForRevisionMismatch(Request $request, int $userId, int $capturedRevision): ?array
    {
        return OfficeErpPasswordRecovery::preservableCompletedForRevisionMismatch($request, $userId, $capturedRevision);
    }

    /** @param array<string, mixed>|null $continuation */
    public static function restoreCompletedRecoveryAfterRevisionMismatch(Request $request, ?array $continuation): void
    {
        OfficeErpPasswordRecovery::restoreAfterRevisionMismatch($request, $continuation);
    }

    public static function assertOwnership(Request $request): void
    {
        $lock = $request->attributes->get('access.session_lock');
        abort_if($lock && ! $lock->isOwnedByCurrentProcess(), 503, 'Serviço temporariamente indisponível');
    }
}
