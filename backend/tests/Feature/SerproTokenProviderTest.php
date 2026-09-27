<?php

namespace Tests\Feature;

use App\Models\SerproConnection;
use App\Services\SerproException;
use App\Services\SerproTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerproTokenProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_it_requests_the_pair_with_the_documented_headers(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'token_type' => 'Bearer',
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $pair = resolve(SerproTokenProvider::class)->pair();

        $this->assertSame('access-1', $pair->accessToken());
        $this->assertSame('jwt-1', $pair->jwtToken());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('TERCEIROS', $request->header('Role-Type')[0]);
            $this->assertSame('application/x-www-form-urlencoded', $request->header('Content-Type')[0]);
            $this->assertStringStartsWith('Basic ', $request->header('Authorization')[0]);
            $this->assertSame('grant_type=client_credentials', $request->body());

            return true;
        });
    }

    public function test_it_reuses_a_cached_pair(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $provider = resolve(SerproTokenProvider::class);
        $provider->pair();
        $provider->pair();

        Http::assertSentCount(1);
    }

    public function test_forget_forces_a_new_acquisition(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $provider = resolve(SerproTokenProvider::class);
        $provider->pair();
        $provider->forget();
        $provider->pair();

        Http::assertSentCount(2);
    }

    public function test_a_rejected_credential_raises_a_do_not_retry_failure(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'message' => 'Não foi possível identificar um certificado digital válido.',
            ], 400),
        ]);

        SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $this->expectException(SerproException::class);

        resolve(SerproTokenProvider::class)->pair();
    }

    public function test_a_response_without_the_authorization_token_is_refused(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
            ]),
        ]);

        SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $this->expectException(SerproException::class);

        resolve(SerproTokenProvider::class)->pair();
    }
}
