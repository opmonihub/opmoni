<?php

namespace App\Support;

use App\Erp\Commercial\OfficeErpInvitationContinuation;
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

    public static function authenticate(Request $request, User $user, ?int $accountId): void
    {
        self::assertOwnership($request);
        $invitationContinuation = OfficeErpInvitationContinuation::preservableForAuthentication($request);
        self::revoke($request);
        Auth::guard('web')->login($user);
        $request->session()->regenerate(true);
        OfficeErpInvitationContinuation::restoreAfterAuthentication($request, $invitationContinuation);
        ErpSession::clearCompanySelection($request);
        $request->session()->put(['access_deadline' => time() + 43200, 'access_authenticated' => true, 'current_account_id' => $accountId]);
    }

    public static function assertOwnership(Request $request): void
    {
        $lock = $request->attributes->get('access.session_lock');
        abort_if($lock && ! $lock->isOwnedByCurrentProcess(), 503, 'Serviço temporariamente indisponível');
    }
}
