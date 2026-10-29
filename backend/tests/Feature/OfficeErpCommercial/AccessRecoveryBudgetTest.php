<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Support\AccessRecoveryBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Tests\TestCase;

class AccessRecoveryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    public function test_sensitive_commands_share_an_actor_and_ip_budget_of_ten_per_minute(): void
    {
        $request = Request::create('/api/v1/erp-commercial/member-access', 'POST', server: ['REMOTE_ADDR' => '192.0.2.15']);
        $budget = app(AccessRecoveryBudget::class);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $budget->sensitive($request, 42);
        }

        try {
            $budget->sensitive($request, 42);
            self::fail('The eleventh sensitive command must be rate limited.');
        } catch (HttpResponseException $exception) {
            self::assertSame(429, $exception->getResponse()->getStatusCode());
            self::assertSame('60', $exception->getResponse()->headers->get('Retry-After'));
            self::assertSame('no-store, private', $exception->getResponse()->headers->get('Cache-Control'));
            self::assertSame('no-referrer', $exception->getResponse()->headers->get('Referrer-Policy'));
        }
    }

    public function test_normalized_identity_budget_allows_one_recovery_issue_per_minute(): void
    {
        $request = Request::create('/api/v1/erp-commercial/member-access', 'POST', server: ['REMOTE_ADDR' => '192.0.2.16']);
        $budget = app(AccessRecoveryBudget::class);
        $budget->sensitive($request, 42, ' Member@Example.COM ');

        try {
            $budget->sensitive($request, 99, 'member@example.com');
            self::fail('A normalized recipient must not receive a second recovery issue inside one minute.');
        } catch (HttpResponseException $exception) {
            self::assertSame(429, $exception->getResponse()->getStatusCode());
            self::assertSame('60', $exception->getResponse()->headers->get('Retry-After'));
            self::assertSame('no-store, private', $exception->getResponse()->headers->get('Cache-Control'));
            self::assertSame('no-referrer', $exception->getResponse()->headers->get('Referrer-Policy'));
        }
    }

    public function test_anonymous_completion_uses_per_ip_limits_without_one_global_actor_bucket(): void
    {
        $budget = app(AccessRecoveryBudget::class);
        $firstIp = Request::create('/api/v1/password-recovery/complete', 'POST', server: ['REMOTE_ADDR' => '192.0.2.17']);
        $secondIp = Request::create('/api/v1/password-recovery/complete', 'POST', server: ['REMOTE_ADDR' => '192.0.2.18']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $budget->sensitive($firstIp, 0);
        }
        self::assertTrue($this->isAllowed(fn () => $budget->sensitive($secondIp, 0)));
    }

    public function test_production_refuses_a_nonredis_sensitive_budget(): void
    {
        config(['app.env' => 'production', 'cache.default' => 'array']);
        $request = Request::create('/api/v1/password-recovery/complete', 'POST', server: ['REMOTE_ADDR' => '192.0.2.19']);

        try {
            app(AccessRecoveryBudget::class)->sensitive($request, 0);
            self::fail('Production cannot use a process-local sensitive budget.');
        } catch (HttpResponseException $exception) {
            self::assertSame(503, $exception->getResponse()->getStatusCode());
            self::assertSame('no-store, private', $exception->getResponse()->headers->get('Cache-Control'));
            self::assertSame('no-referrer', $exception->getResponse()->headers->get('Referrer-Policy'));
        }
    }

    private function isAllowed(callable $attempt): bool
    {
        try {
            $attempt();

            return true;
        } catch (HttpResponseException) {
            return false;
        }
    }
}
