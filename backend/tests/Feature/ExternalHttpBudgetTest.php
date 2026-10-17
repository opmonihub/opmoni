<?php

namespace Tests\Feature;

use App\Support\ExternalHttpBudget;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExternalHttpBudgetTest extends TestCase
{
    public function test_request_budget_uses_lock_acquisition_not_construction_time_and_reserves_confirmation(): void
    {
        $time = 112.0;
        $request = Request::create('/');
        $request->attributes->set('access.session_lock', new class
        {
            public function isOwnedByCurrentProcess(): bool
            {
                return true;
            }
        });
        $request->attributes->set('access.session_lock_acquired_at', 100.0);
        config(['access.lock_seconds' => 30]);
        $budget = ExternalHttpBudget::fromRequest($request, fn (): float => $time);
        $this->assertNotNull($budget);
        $this->assertSame($budget, ExternalHttpBudget::fromRequest($request));
        $options = $budget->options();
        $this->assertSame(12.0, $options['timeout']);
        $this->assertSame(3.0, $options['connect_timeout']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(2.0, $budget->options(2.0)['timeout']);
        $this->assertSame(2.0, $budget->options(2.0)['connect_timeout']);
        $this->assertSame(12.0, $budget->options(60.0)['timeout']);
        $this->assertNull(ExternalHttpBudget::fromRequest(Request::create('/job')));
    }

    public function test_lost_ownership_before_or_after_arming_prevents_send(): void
    {
        foreach ([false, true] as $loseDuringArm) {
            $owned = $loseDuringArm;
            $armed = false;
            $request = Request::create('/');
            $request->attributes->set('access.session_lock', new class($owned)
            {
                public function __construct(private bool &$owned) {}

                public function isOwnedByCurrentProcess(): bool
                {
                    return $this->owned;
                }
            });
            $request->attributes->set('access.session_lock_acquired_at', 100.0);
            $budget = ExternalHttpBudget::fromRequest($request, fn (): float => 101.0);
            try {
                $budget->beforeSend(function () use (&$owned, &$armed): void {
                    $armed = true;
                    $owned = false;
                });
                $this->fail('Ownership loss must abort before HTTP.');
            } catch (HttpException $exception) {
                $this->assertSame(503, $exception->getStatusCode());
                $this->assertSame($loseDuringArm, $armed);
            }
        }
    }

    public function test_time_consumed_by_arm_is_rechecked_and_short_lease_is_not_extended(): void
    {
        $time = 100.0;
        $request = Request::create('/');
        $request->attributes->set('access.session_lock', new class
        {
            public function isOwnedByCurrentProcess(): bool
            {
                return true;
            }
        });
        $request->attributes->set('access.session_lock_acquired_at', $time);
        config(['access.lock_seconds' => 10]);
        $budget = ExternalHttpBudget::fromRequest($request, function () use (&$time): float {
            return $time;
        });
        $this->assertSame(4.0, $budget->options()['timeout']);
        $this->expectException(HttpException::class);
        $budget->beforeSend(function () use (&$time): void {
            $time = 105.0;
        });
    }
}
