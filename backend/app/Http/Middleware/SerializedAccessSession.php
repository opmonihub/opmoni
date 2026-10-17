<?php

namespace App\Http\Middleware;

use App\Support\AccessSession;
use App\Support\ExternalHttpBudget;
use Closure;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SerializedAccessSession extends StartSession
{
    public function handle($request, Closure $next): Response
    {
        $session = $this->getSession($request);
        $lock = Cache::lock('access-session:'.hash('sha256', $session->getId()), (int) config('access.lock_seconds', 30));
        try {
            $lock->block((int) config('access.lock_wait', 2));
            $request->attributes->set('access.session_lock_acquired_at', ExternalHttpBudget::monotonicNow());
            $request->attributes->set('access.session_lock', $lock);

            return $this->handleStatefulRequest($request, $session, $next);
        } finally {
            $lock->release();
        }
    }

    protected function saveSession($request): void
    {
        AccessSession::assertOwnership($request);
        parent::saveSession($request);
    }

    protected function storeCurrentUrl($request, $session): void {}

    protected function addCookieToResponse($response, $session): void
    {
        if ($session->get('access_logout')) {
            $response->headers->clearCookie($session->getName(), '/', null, (bool) config('session.secure'), true, 'lax');

            return;
        }

        parent::addCookieToResponse($response, $session);
    }

    protected function getCookieExpirationDate(): int
    {
        return $this->manager->driver()->get('access_deadline', time() + 1800);
    }
}
