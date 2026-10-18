<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\SerproConnectivity;
use App\Services\SerproCredentialLifecycle;
use App\Services\SerproException;
use App\Services\SerproTokenProvider;
use App\Support\ExternalHttpBudget;
use Illuminate\Cache\ArrayLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\BusinessTestCase;

class SerproDiagnosticAuthenticationTest extends BusinessTestCase
{
    use RefreshDatabase;

    private function connection(): SerproConnection
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['CN' => 'TEST:12345678000195', 'serialNumber' => '12345678000195'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_pkcs12_export($cert, $bytes, $key, 'fixture');

        return SerproConnection::factory()->create([
            'consumer_key' => 'test-key', 'consumer_secret_encrypted' => Crypt::encryptString('test-secret'),
            'certificate_encrypted' => Crypt::encryptString($bytes), 'certificate_password_encrypted' => Crypt::encryptString('fixture'),
        ]);
    }

    public function test_diagnostic_discards_tokens_and_uses_bounded_verified_protocol(): void
    {
        $this->connection();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Cache::put('serpro:token-pair', 'existing-operational-value', 60);
        Log::spy();
        $level = DB::transactionLevel();
        Http::fake(function ($request, $options) use ($level) {
            $this->assertSame($level, DB::transactionLevel(), 'HTTP must run after releasing lifecycle transaction locks.');
            $this->assertSame('Basic '.base64_encode('test-key:test-secret'), $request->header('Authorization')[0]);
            $this->assertSame(['TERCEIROS'], $request->header('Role-Type'));
            $this->assertSame('client_credentials', $request['grant_type']);
            $this->assertSame(false, $options['allow_redirects']);
            $this->assertSame(true, $options['verify']);
            $this->assertSame(3.0, (float) $options['connect_timeout']);
            $this->assertSame(8.0, (float) $options['timeout']);
            $this->assertSame('P12', $options['cert_type']);
            $this->assertFileExists($options['cert'][0]);

            return Http::response(['access_token' => 'discard-access', 'jwt_token' => 'discard-jwt']);
        });
        $this->postJson('/api/v1/serpro/connectivity')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('existing-operational-value', Cache::get('serpro:token-pair'));
        $snapshot = app(SerproCredentialLifecycle::class)->snapshot();
        $this->assertNull(Cache::get('serpro:token-pair:'.$snapshot->generation()));
        foreach (['debug', 'info', 'warning', 'error'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public function test_rotation_during_provider_request_returns_conflict_and_cannot_publish_stale_operational_tokens(): void
    {
        $connection = $this->connection();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $calls = 0;
        Http::fake(function () use ($connection, &$calls) {
            $calls++;
            if ($calls <= 2) {
                $connection->update(['consumer_key' => 'rotated-key-'.$calls, 'revision' => $connection->revision + 1]);
            }

            return Http::response(['access_token' => 'access-'.$calls, 'jwt_token' => 'jwt-'.$calls, 'expires_in' => 3600]);
        });
        $this->postJson('/api/v1/serpro/connectivity')->assertStatus(409)->assertJsonPath('code', 'connection_changed');
        try {
            app(SerproTokenProvider::class)->pair();
            $this->fail('Stale authentication must be refused.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::NotSent, $exception->failure);
        }
        $this->assertSame('jwt-3', app(SerproTokenProvider::class)->pair()->jwtToken());
        $this->assertSame('jwt-3', app(SerproTokenProvider::class)->pair()->jwtToken());
        Http::assertSentCount(3);
    }

    public function test_shared_trusted_ip_limit_cannot_be_bypassed_with_forwarding_headers(): void
    {
        Http::preventStrayRequests();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs(User::factory()->create(['is_super_admin' => true]));
            $this->withHeader('X-Forwarded-For', '192.0.2.'.($i + 1))->postJson('/api/v1/serpro/connectivity')->assertOk();
        }
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->postJson('/api/v1/serpro/connectivity')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_distinct_trusted_ips_have_independent_diagnostic_budgets(): void
    {
        Http::preventStrayRequests();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs(User::factory()->create(['is_super_admin' => true]));
            $this->withHeader('X-Opmoni-Client-IP', '192.0.2.10')->postJson('/api/v1/serpro/connectivity')->assertOk();
        }

        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->withHeader('X-Opmoni-Client-IP', '192.0.2.20')->postJson('/api/v1/serpro/connectivity')->assertOk();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->withHeader('X-Opmoni-Client-IP', '192.0.2.10')->withHeader('X-Forwarded-For', '192.0.2.30')
            ->postJson('/api/v1/serpro/connectivity')->assertStatus(429)->assertHeader('Retry-After');
        Http::assertNothingSent();
    }

    public function test_actor_diagnostic_budget_survives_trusted_ip_changes(): void
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create(['is_super_admin' => true]);
        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($actor);
            $this->withHeader('X-Opmoni-Client-IP', '192.0.2.'.$i)->postJson('/api/v1/serpro/connectivity')->assertOk();
        }

        $this->actingAs($actor);
        $this->withHeader('X-Opmoni-Client-IP', '192.0.2.7')->postJson('/api/v1/serpro/connectivity')->assertStatus(429)->assertHeader('Retry-After');
        Http::assertNothingSent();
    }

    #[DataProvider('providerFailures')]
    public function test_sanitized_provider_failures(int $status, array $body, string $element): void
    {
        $this->connection();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Http::fake(['*' => Http::response($body, $status, ['Location' => 'https://untrusted.invalid'])]);
        $response = $this->postJson('/api/v1/serpro/connectivity')->assertOk()->assertJsonPath('data.failed_element', $element);
        $this->assertStringNotContainsString('provider-secret', $response->getContent());
        Http::assertSentCount(1);
    }

    public static function providerFailures(): array
    {
        return [
            [401, ['message' => 'provider-secret'], 'credencial'],
            [429, ['message' => 'provider-secret'], 'provedor'],
            [503, ['message' => 'provider-secret'], 'provedor'],
            [302, [], 'provedor'], [200, ['access_token' => 'provider-secret'], 'provedor'],
            [200, ['access_token' => ['provider-secret'], 'jwt_token' => 'jwt'], 'provedor'],
        ];
    }

    public function test_timeout_is_sanitized_without_cache_or_database_writes(): void
    {
        $connection = $this->connection();
        $before = $connection->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Http::fake(fn () => throw new ConnectionException('provider-secret'));
        $response = $this->postJson('/api/v1/serpro/connectivity')->assertOk()->assertJsonPath('data.failed_element', 'provedor');
        $this->assertSame($before, $connection->fresh()->getRawOriginal());
        $this->assertStringNotContainsString('provider-secret', $response->getContent());
    }

    public function test_actor_budget_spans_trusted_ips(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        for ($i = 1; $i <= 6; $i++) {
            $this->withHeader('X-Opmoni-Client-IP', '192.0.2.'.$i)->postJson('/api/v1/serpro/connectivity')->assertOk();
        }
        $this->withHeader('X-Opmoni-Client-IP', '192.0.2.7')->postJson('/api/v1/serpro/connectivity')
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'rate_limited');
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_endpoint_and_budgets_fail_before_http(string $key, mixed $value): void
    {
        $this->connection();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        config([$key => $value]);
        Http::preventStrayRequests();
        $this->postJson('/api/v1/serpro/connectivity')->assertOk()->assertJsonPath('data.failed_element', 'provedor');
        Http::assertNothingSent();
    }

    public static function unsafeConfigurations(): array
    {
        return [
            ['integra-contador.auth_url', 'http://autenticacao.sapi.serpro.gov.br/authenticate'],
            ['integra-contador.auth_url', 'https://untrusted.invalid/authenticate'],
            ['integra-contador.diagnostic_timeout', 26], ['integra-contador.diagnostic_connect_timeout', 4],
            ['integra-contador.diagnostic_proxy_timeout', 31], ['access.lock_seconds', 20],
        ];
    }

    public function test_lost_session_lock_before_http_prevents_authentication(): void
    {
        $this->connection();
        Http::preventStrayRequests();
        $lock = \Mockery::mock(ArrayLock::class);
        $lock->shouldReceive('isOwnedByCurrentProcess')->andReturn(false);
        request()->attributes->set('access.session_lock', $lock);
        try {
            app(SerproConnectivity::class)->check();
            $this->fail('Expired lock must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_lost_session_lock_after_http_discards_verdict(): void
    {
        $this->connection();
        $lock = new class
        {
            public bool $owned = true;

            public function isOwnedByCurrentProcess(): bool
            {
                return $this->owned;
            }
        };
        request()->attributes->set('access.session_lock', $lock);
        request()->attributes->set('access.session_lock_acquired_at', ExternalHttpBudget::monotonicNow());
        Http::fake(function () use ($lock) {
            $lock->owned = false;

            return Http::response(['access_token' => 'discard', 'jwt_token' => 'discard']);
        });
        try {
            app(SerproConnectivity::class)->check();
            $this->fail('Expired lock must discard the verdict.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        Http::assertSentCount(1);
    }
}
