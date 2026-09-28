<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use App\Services\SerproException;
use App\Services\SerproTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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

    private function connection(): SerproConnection
    {
        // PFX real, com o mesmo CNPJ da coluna de contratante: a autenticação
        // confere a identidade antes de sair para a rede, e um certificado de
        // mentira faria estes testes medirem outra coisa.
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(
            ['CN' => 'SERPRO PLATAFORMA LTDA:12345678000195', 'serialNumber' => '12345678000195'],
            $key,
            array_merge(['digest_alg' => 'sha256'], $config),
        );
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'senha'));

        return SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($pfx),
            'certificate_password_encrypted' => Crypt::encryptString('senha'),
            'contratante_numero' => '12345678000195',
            'certificate_valid_until' => now()->addYear(),
        ]);
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

        $this->connection();

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

        $this->connection();

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

        $this->connection();

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

        $this->connection();

        try {
            resolve(SerproTokenProvider::class)->pair();
            $this->fail('A rejected credential should throw a SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertSame(400, $exception->status);
        }
    }

    public function test_a_response_without_the_authorization_token_is_refused(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
            ]),
        ]);

        $this->connection();

        try {
            resolve(SerproTokenProvider::class)->pair();
            $this->fail('A response missing jwt_token should throw a SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Upstream, $exception->failure);
            $this->assertSame(502, $exception->status);
        }
    }

    public function test_an_unreachable_provider_raises_an_upstream_failure(): void
    {
        Http::fake(Http::failedConnection('connection refused'));

        $this->connection();

        try {
            resolve(SerproTokenProvider::class)->pair();
            $this->fail('An unreachable auth provider should throw a SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Upstream, $exception->failure);
            $this->assertSame(503, $exception->status);
        }
    }
}
