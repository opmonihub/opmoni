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

    /**
     * O provedor de token responde bem uma vez — o par que fica em cache — e
     * falha em todas as seguintes. Um segundo `Http::fake()` não serviria: os
     * stubs são somados, e o primeiro registrado continua respondendo, o que
     * faria a verificação passar com o token que ela deveria ter recusado.
     */
    private function falhandoDepoisDaPrimeiraEmissao(int $status): void
    {
        $chamadas = 0;

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => function () use (&$chamadas, $status) {
                $chamadas++;

                return $chamadas === 1
                    ? Http::response([
                        'expires_in' => 2008,
                        'token_type' => 'Bearer',
                        'access_token' => 'access-1',
                        'jwt_token' => 'jwt-1',
                    ])
                    : Http::response(['message' => 'Limite de requisições.'], $status);
            },
        ]);
    }

    public function test_verificar_com_o_provedor_fora_do_ar_nao_joga_fora_o_par_valido(): void
    {
        // A verificação descarta o par guardado antes de tentar, e é por isso
        // que ela precisa devolver o que tinha quando a falha não diz nada
        // sobre a credencial: descartar primeiro e falhar depois deixa o cache
        // vazio, e a sincronização seguinte tem de se autenticar no meio da
        // mesma falha que a verificação acabou de ter. Um provedor fora do ar
        // não recusa chave nenhuma — o par de meia hora atrás continua válido.
        $this->falhandoDepoisDaPrimeiraEmissao(503);
        $this->connection();

        $provider = resolve(SerproTokenProvider::class);
        $provider->pair();

        try {
            $provider->verify();
            $this->fail('Um provedor fora do ar deveria levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Upstream, $exception->failure);
        }

        // O par anterior sobreviveu à verificação e é o que a execução seguinte
        // reaproveita: a falha foi da rede, não da credencial.
        $this->assertSame('access-1', $provider->pair()->accessToken());

        Http::assertSentCount(2);
    }

    public function test_verificar_com_um_limite_de_tentativas_nao_joga_fora_o_par_valido(): void
    {
        // `429` é o provedor mandando esperar, e esperar é a única ação. O par
        // em cache não é o problema, e devolvê-lo para a sincronização é o que
        // permite que ela siga durante a espera.
        $this->falhandoDepoisDaPrimeiraEmissao(429);
        $this->connection();

        $provider = resolve(SerproTokenProvider::class);
        $provider->pair();

        try {
            $provider->verify();
            $this->fail('Um limite de tentativas deveria levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Throttled, $exception->failure);
        }

        $this->assertSame('access-1', $provider->pair()->accessToken());
    }

    public function test_verificar_com_uma_credencial_recusada_joga_fora_o_par_anterior(): void
    {
        // O contrário da rede: a recusa é do que foi enviado, e o par em cache
        // saiu exatamente daquela credencial. Devolvê-lo só compra um `401` na
        // próxima chamada — que o `SerproClient` transformaria em uma segunda
        // tentativa com o mesmo token. Aqui o descarte é o conserto.
        $this->falhandoDepoisDaPrimeiraEmissao(401);
        $this->connection();

        $provider = resolve(SerproTokenProvider::class);
        $provider->pair();

        try {
            $provider->verify();
            $this->fail('Uma credencial recusada deveria levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
        }

        try {
            $provider->pair();
            $this->fail('O par recusado não pode sobreviver à recusa.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
        }

        // A terceira chamada é a que prova o descarte: `pair()` não devolveu
        // `access-1` de onde estava, foi pedir um par novo.
        Http::assertSentCount(3);
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

    public function test_um_limite_de_tentativas_nao_vira_recusa_de_credencial(): void
    {
        // O `429` é o único `4xx` que não é recusa: é o provedor mandando
        // esperar. Tratar o `4xx` inteiro como recusa punia duas vezes quem está
        // só com o serviço ocupado — com o rótulo de credencial e com a
        // instrução de redigitar chave, segredo e certificado, que nada disso
        // resolve. `classify()` já devolve `Throttled` para `429`; a
        // autenticação precisa concordar com a chamada de serviço.
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response(['message' => 'Limite de requisições.'], 429),
        ]);

        $this->connection();

        try {
            resolve(SerproTokenProvider::class)->pair();
            $this->fail('Um limite de tentativas deveria levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Throttled, $exception->failure);
            $this->assertSame(429, $exception->status);
            $this->assertStringNotContainsString('Limite de requisições.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_um_erro_do_servidor_nao_vira_recusa_de_credencial(): void
    {
        // Recusa e indisponibilidade pedem conserto oposto — corrigir a
        // credencial ou esperar o provedor —, e as duas não podem sair daqui com
        // o mesmo rótulo. O `5xx` é o serviço que não deu conta; o `4xx` acima
        // é o que foi enviado que ele não aceitou.
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response(['message' => 'Erro interno.'], 503),
        ]);

        $this->connection();

        try {
            resolve(SerproTokenProvider::class)->pair();
            $this->fail('Um erro do servidor deveria levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Upstream, $exception->failure);
            $this->assertSame(503, $exception->status);
        }
    }

    public function test_um_segredo_ilegivel_vira_falha_nomeada_e_nao_excecao_de_cifra(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        $connection = $this->connection();

        // `APP_KEY` girada, coluna truncada, linha restaurada de outro ambiente: o
        // segredo guardado não abre. Um `DecryptException` subindo de dentro de uma
        // requisição HTTP chega ao consumidor como `500` — e o consumidor é quem
        // precisa saber que nada foi enviado e que a correção é na credencial.
        $connection->forceFill(['consumer_secret_encrypted' => 'cifrado-que-nao-abre'])->save();

        try {
            resolve(SerproTokenProvider::class)->pair();
            $this->fail('Um segredo ilegível deveria levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::NotSent, $exception->failure);
            $this->assertStringNotContainsString('cifrado-que-nao-abre', $exception->getMessage());
        }

        Http::assertNothingSent();
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
