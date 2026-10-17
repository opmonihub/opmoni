<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;

final class ExternalHttpBudget
{
    private const CONFIRMATION_RESERVE = 1.0;

    private function __construct(private Request $request, private float $cutoff, private Closure $clock) {}

    public static function monotonicNow(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public static function fromRequest(Request $request, ?Closure $clock = null): ?self
    {
        if (! $request->attributes->has('access.session_lock')) {
            return null;
        }
        $existing = $request->attributes->get(self::class);
        if ($existing instanceof self) {
            return $existing;
        }
        $started = $request->attributes->get('access.session_lock_acquired_at');
        abort_unless(is_float($started) || is_int($started), 503, 'Orçamento HTTP indisponível.');
        $budget = new self($request, $started + min(25.0, max(0.0, (float) config('access.lock_seconds', 30) - 5.0)), $clock ?? self::monotonicNow(...));
        $request->attributes->set(self::class, $budget);

        return $budget;
    }

    public function assertAvailable(): void
    {
        AccessSession::assertOwnership($this->request);
        abort_if($this->cutoff - ($this->clock)() <= self::CONFIRMATION_RESERVE, 503, 'Tempo seguro para comunicação externa esgotado.');
    }

    public function beforeSend(?Closure $beforeEffect = null): void
    {
        $this->assertAvailable();
        if ($beforeEffect !== null) {
            $beforeEffect();
        }
        $this->assertAvailable();
    }

    /** @return array{timeout: float, connect_timeout: float, allow_redirects: false} */
    public function options(?float $configuredTimeout = null): array
    {
        $this->assertAvailable();
        $timeout = $this->cutoff - ($this->clock)() - self::CONFIRMATION_RESERVE;
        if ($configuredTimeout !== null) {
            abort_unless(is_finite($configuredTimeout) && $configuredTimeout > 0, 503, 'Timeout externo inválido.');
            $timeout = min($configuredTimeout, $timeout);
        }
        abort_if($timeout <= 0, 503, 'Tempo seguro para comunicação externa esgotado.');

        return ['timeout' => $timeout, 'connect_timeout' => min(3.0, $timeout), 'allow_redirects' => false];
    }
}
