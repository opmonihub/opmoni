<?php

namespace Tests\Feature;

use App\Services\WhatsApp\WzapClient;
use App\Services\WhatsApp\WzapException;
use App\Support\ExternalHttpBudget;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExternalTransportBudgetTest extends TestCase
{
    private function budget(): ExternalHttpBudget
    {
        $request = Request::create('/');
        $request->attributes->set('access.session_lock', new class
        {
            public function isOwnedByCurrentProcess(): bool
            {
                return true;
            }
        });
        $request->attributes->set('access.session_lock_acquired_at', 100.0);

        return ExternalHttpBudget::fromRequest($request, fn (): float => 110.0);
    }

    public function test_wzap_budget_arm_and_result_callback_precede_return_with_bounded_http_options(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global']);
        $armed = false;
        $recorded = false;
        Http::fake(function (ClientRequest $request, array $options) use (&$armed) {
            $this->assertTrue($armed);
            $this->assertSame(14.0, $options['timeout']);
            $this->assertSame(3.0, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['data' => ['id' => 'inst-1', 'instance_api_key' => 'one-time-key']], 201);
        });
        $result = app(WzapClient::class)->createInstance('acc-1', 'Account', $this->budget(),
            function () use (&$armed): void {
                $armed = true;
            },
            function (array $result, int $status) use (&$recorded): void {
                $this->assertSame('inst-1', $result['id']);
                $this->assertSame(201, $status);
                $recorded = true;
            });
        $this->assertTrue($recorded);
        $this->assertSame('inst-1', $result['id']);
    }

    public function test_wzap_budget_respects_lower_configured_timeout(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global', 'whatsapp.wzap.timeout' => 2]);
        Http::fake(function (ClientRequest $request, array $options) {
            $this->assertSame(2.0, $options['timeout']);
            $this->assertSame(2.0, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['data' => ['instance_api_key' => 'key']]);
        });
        app(WzapClient::class)->rotateInstanceKey('inst-1', $this->budget());
    }

    public function test_configure_webhook_exposes_guarded_patch_and_callbacks_but_does_not_arm_when_unconfigured(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global', 'whatsapp.wzap.webhook_url' => null]);
        Http::fake();
        $armed = false;
        $confirmed = false;
        $before = function () use (&$armed): void {
            $armed = true;
        };
        $after = function (array $result, int $status) use (&$confirmed): void {
            $this->assertSame(204, $status);
            $confirmed = true;
        };
        $client = app(WzapClient::class);
        $client->configureWebhook('inst-1', $this->budget(), $before, $after);
        $this->assertFalse($armed);
        $this->assertFalse($confirmed);
        Http::assertNothingSent();
        config(['whatsapp.wzap.webhook_url' => 'https://opmoni.test/webhook']);
        Http::swap(new Factory);
        Http::fake(function (ClientRequest $request, array $options) use (&$armed) {
            $this->assertTrue($armed);
            $this->assertSame('PATCH', $request->method());
            $this->assertSame(['receipt', 'connection', 'message.status'], $request['webhook']['events']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(null, 204);
        });
        $client->configureWebhook('inst-1', $this->budget(), $before, $after);
        $this->assertTrue($confirmed);
        Http::assertSentCount(1);
    }

    public function test_find_instance_is_paginated_get_only_without_hidden_webhook_or_creation(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global', 'whatsapp.wzap.webhook_url' => 'https://opmoni.test/webhook']);
        Http::fakeSequence()
            ->push(['data' => ['instances' => [], 'next_cursor' => 'page-2']])
            ->push(['data' => ['instances' => [['id' => 'inst-1', 'name' => 'Account']]]]);
        $this->assertSame('inst-1', app(WzapClient::class)->findInstance('acc-1', 'Account', $this->budget())['id']);
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => ($request->data()['cursor'] ?? null) === 'page-2');
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_find_instance_returns_null_when_absent_and_rejects_repeated_cursor(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global']);
        Http::fake(['*' => Http::response(['data' => ['items' => []]])]);
        $this->assertNull(app(WzapClient::class)->findInstance('acc-1', 'Account', $this->budget()));
        Http::swap(new Factory);
        Http::fakeSequence()
            ->push(['data' => ['items' => [], 'next_cursor' => 'same']])
            ->push(['data' => ['items' => [], 'next_cursor' => 'same']]);
        $this->expectException(WzapException::class);
        app(WzapClient::class)->findInstance('acc-1', 'Account', $this->budget());
    }

    public function test_find_instance_limits_discovery_to_twenty_pages(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global']);
        $sequence = Http::fakeSequence();
        for ($page = 1; $page <= 20; $page++) {
            $sequence->push(['data' => ['items' => [], 'next_cursor' => 'page-'.$page]]);
        }
        try {
            app(WzapClient::class)->findInstance('acc-1', 'Account', $this->budget());
            $this->fail('Discovery must stop at twenty pages.');
        } catch (WzapException $exception) {
            $this->assertStringContainsString('paginação', $exception->getMessage());
        }
        Http::assertSentCount(20);
    }

    public function test_wzap_does_not_send_after_ownership_is_lost_during_arm(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global']);
        Http::fake();
        $owned = true;
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
            app(WzapClient::class)->rotateInstanceKey('inst-1', $budget, function () use (&$owned): void {
                $owned = false;
            });
            $this->fail('Lost lock must prevent transfer.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_wzap_does_not_retry_or_sanitize_journal_failure_as_provider_failure(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global']);
        Http::fake(['*' => Http::response(['data' => ['instance_api_key' => 'key']])]);
        try {
            app(WzapClient::class)->rotateInstanceKey('inst-1', $this->budget(), null, function (): void {
                throw new RuntimeException('journal failed');
            });
            $this->fail('Receipt failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('journal failed', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_ensure_instance_never_treats_receipt_conflict_as_provider_conflict(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global']);
        Http::fake(['*' => Http::response(['data' => ['id' => 'inst-1']], 201)]);
        try {
            app(WzapClient::class)->ensureInstance('acc-1', 'Account', onResult: function (): void {
                throw new WzapException('receipt conflict', 409);
            });
            $this->fail('Receipt failure must not enter provider fallback.');
        } catch (WzapException $exception) {
            $this->assertSame('receipt conflict', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_ensure_instance_stops_repeated_pagination_cursor(): void
    {
        config(['whatsapp.wzap.base_url' => 'http://wzap.test', 'whatsapp.wzap.api_key' => 'global', 'whatsapp.wzap.webhook_url' => null]);
        Http::fakeSequence()->push(['error' => ['code' => 'conflict']], 409)
            ->push(['data' => ['instances' => [], 'next_cursor' => 'same']])
            ->push(['data' => ['instances' => [], 'next_cursor' => 'same']]);
        try {
            app(WzapClient::class)->ensureInstance('acc-1', 'Account', budget: $this->budget());
            $this->fail('Repeated cursor must stop.');
        } catch (WzapException $exception) {
            $this->assertStringContainsString('paginação', $exception->getMessage());
        }
        Http::assertSentCount(3);
    }
}
