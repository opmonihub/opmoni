<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use App\Services\SerproClient;
use App\Services\SerproException;
use App\Services\SerproTokenPair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerproClientTest extends TestCase
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
        // O PFX é real, e o seu CNPJ é o mesmo que a coluna de contratante:
        // a chamada passa por `assertIdentity()` antes de qualquer rede, e um
        // certificado de mentira seria recusado por um motivo que nada tem a ver
        // com o que estes testes exercitam.
        $pfx = $this->platformPfx();

        return SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($pfx),
            'certificate_password_encrypted' => Crypt::encryptString('senha'),
            'contratante_numero' => '12345678000195',
            'contratante_tipo' => 2,
            'certificate_valid_until' => now()->addYear(),
        ]);
    }

    /**
     * Certificado da plataforma gerado em runtime: `.pfx` é ignorado pelo git,
     * então um fixture versionado não existe.
     */
    private function platformPfx(): string
    {
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

        return $pfx;
    }

    private function fakeTokens(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);
    }

    private function cacheTokenPair(): void
    {
        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 600);
    }

    public function test_it_calls_the_operation_path_with_the_documented_headers(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 200,
                'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
                'mensagens' => [],
            ]),
        ]);

        $result = resolve(SerproClient::class)->call(
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            ['anoCalendario' => 2023],
            '33683111000107',
            '33683111000875',
        );

        $this->assertSame(200, $result->status());
        $this->assertSame([['anoCalendario' => 2023, 'regimeApurado' => 'CAIXA']], $result->dados());
        $this->assertSame(32, strlen($result->requestTag()));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/Consultar')) {
                return false;
            }

            $this->assertSame('Bearer access-1', $request->header('Authorization')[0]);
            $this->assertSame('jwt-1', $request->header('jwt_token')[0]);
            $this->assertSame(32, strlen($request->header('X-Request-Tag')[0]));

            $body = $request->data();
            $this->assertSame('12345678000195', $body['contratante']['numero']);
            $this->assertSame('33683111000107', $body['autorPedidoDados']['numero']);
            $this->assertSame('33683111000875', $body['contribuinte']['numero']);
            $this->assertIsString($body['pedidoDados']['dados']);
            $this->assertSame('{"anoCalendario":2023}', $body['pedidoDados']['dados']);

            return true;
        });
    }

    public function test_the_request_tag_carries_the_author_and_the_contributor_not_the_platform(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 200,
                'dados' => '[]',
                'mensagens' => [],
            ]),
        ]);

        $result = resolve(SerproClient::class)->call(
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            [],
            '33683111000107',
            '33683111000875',
            serviceSequence: 1,
        );

        $expectedTag = '23368311100010723368311100087501';

        $this->assertSame($expectedTag, $result->requestTag());
        $this->assertStringNotContainsString('12345678000195', $result->requestTag());

        Http::assertSent(function (Request $request) use ($expectedTag): bool {
            if (! str_contains($request->url(), 'gateway.apiserpro.serpro.gov.br')) {
                return false;
            }

            $this->assertSame($expectedTag, $request->header('X-Request-Tag')[0]);

            return true;
        });
    }

    public function test_it_reads_the_path_and_version_from_the_service_map(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 200,
                'dados' => '[]',
                'mensagens' => [],
            ]),
        ]);

        $client = resolve(SerproClient::class);

        $client->call('REGIMEAPURACAO', 'CONSULTARANOSCALENDARIOS102', [], '33683111000107', '33683111000875');
        $client->call('SITFIS', 'RELATORIOSITFIS92', [], '33683111000107', '33683111000875');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/Consultar')) {
                return false;
            }

            $this->assertSame('1.0', $request->data()['pedidoDados']['versaoSistema']);

            return true;
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/Emitir')) {
                return false;
            }

            $this->assertSame('2.0', $request->data()['pedidoDados']['versaoSistema']);

            return true;
        });
    }

    public function test_it_reauthenticates_once_on_401_and_replays(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::sequence()
                ->push(['status' => 401, 'mensagens' => []], 401)
                ->push([
                    'status' => 200,
                    'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
                    'mensagens' => [],
                ], 200),
        ]);

        $result = resolve(SerproClient::class)->call(
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            [],
            '33683111000107',
            '33683111000875',
        );

        $this->assertSame(200, $result->status());

        $gatewayCalls = Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'gateway.apiserpro.serpro.gov.br'),
        );

        $this->assertCount(2, $gatewayCalls);
    }

    public function test_it_stops_after_one_reauthentication_when_the_replay_also_fails(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response(['status' => 401, 'mensagens' => []], 401),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um 401 persistente deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Reauthenticate, $exception->failure);
            $this->assertSame(401, $exception->status);
        }

        $gatewayCalls = Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'gateway.apiserpro.serpro.gov.br'),
        );

        $this->assertCount(2, $gatewayCalls);
    }

    public function test_a_timeout_raises_an_indeterminate_failure(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 504,
                'dados' => null,
                'mensagens' => [['codigo' => 'Erro-REGIME-058', 'texto' => 'Não foi possível obter resposta do serviço.']],
            ], 504),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um 504 deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Indeterminate, $exception->failure);
            $this->assertSame(504, $exception->status);
        }
    }

    public function test_a_missing_procuracao_is_never_retried(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 403,
                'dados' => null,
                'mensagens' => [[
                    'codigo' => 'AcessoNegado-ICGERENCIADOR-022',
                    'texto' => 'Não possui procuração outorgada no e-CAC para o contribuinte.',
                ]],
            ], 403),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'SITFIS',
                'RELATORIOSITFIS92',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Uma falta de procuração deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertSame('AcessoNegado-ICGERENCIADOR-022', $exception->providerCode);
        }

        $gatewayCalls = Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'gateway.apiserpro.serpro.gov.br'),
        );

        $this->assertCount(1, $gatewayCalls);
    }

    public function test_an_absent_connection_is_refused_without_touching_the_network(): void
    {
        $this->fakeTokens();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response(['status' => 200], 200),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Uma conexão ausente deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
        }

        Http::assertNothingSent();
    }

    public function test_an_unmapped_service_is_refused_without_touching_the_network(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response(['status' => 200], 200),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'SERVICOINEXISTENTE999',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um serviço não mapeado deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertStringContainsString('SERVICOINEXISTENTE999', $exception->getMessage());
        }

        $gatewayCalls = Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'gateway.apiserpro.serpro.gov.br'),
        );

        $this->assertCount(0, $gatewayCalls);
    }

    /**
     * O erro do gateway não tem envelope: são `code`, `message` e `description`,
     * e o texto é livre. Esse texto é o que o provedor devolveu sobre a
     * requisição que fizemos, e a requisição carrega o token e o documento do
     * cliente — a exceção é a mensagem que vai para o log e para a tela, então
     * o que ela carrega é o rótulo da falha e o código, nunca o corpo.
     */
    public function test_um_erro_de_gateway_nao_devolve_o_texto_do_provedor(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'code' => '900807',
                'message' => 'Token access-1 recusado para o contribuinte 33683111000107.',
                'description' => 'Authorization: Bearer access-1 (jwt_token jwt-1)',
            ], 429),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um erro de gateway deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Throttled, $exception->failure);
            $this->assertSame(429, $exception->status);
            // O código do gateway é o que classifica a falha, e ele é um código
            // da tabela documentada, não o texto que veio junto.
            $this->assertSame('900807', $exception->providerCode);
            $this->assertSame(SerproFailure::Throttled->label(), $exception->getMessage());
            $this->assertStringNotContainsString('access-1', $exception->getMessage());
            $this->assertStringNotContainsString('jwt-1', $exception->getMessage());
            $this->assertStringNotContainsString('33683111000107', $exception->getMessage());
        }
    }

    /**
     * Uma mensagem de aplicação sem texto é o mesmo caso: a exceção sobe com o
     * rótulo da falha, e um log que só diz `SerproException` não diz nada.
     */
    public function test_uma_mensagem_vazia_cai_no_rotulo_da_falha(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 500,
                'dados' => null,
                'mensagens' => [['codigo' => 'Erro-REGIME-000', 'texto' => '']],
            ], 500),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um 500 deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Upstream, $exception->failure);
            $this->assertSame(SerproFailure::Upstream->label(), $exception->getMessage());
            $this->assertSame('Erro-REGIME-000', $exception->providerCode);
        }
    }

    /**
     * O `code` do gateway é o único texto que sai do corpo de um erro sem
     * envelope, e ele só é lido quando é texto. Um objeto ali viraria a string
     * `"Array"` — com um aviso no log — e passaria a valer como se fosse um
     * código da tabela.
     */
    /**
     * Nenhuma forma observada do gateway traz `mensagens` — a fixture
     * `gateway-429.json` não tem. O teste acima fecha a porta para o `code` e o
     * `message`; este fecha para a hipótese de um corpo de gateway que por
     * acaso trouxesse o envelope.
     *
     * Sem a guarda, o `codigo` de lá entraria no lugar do `code` do gateway e o
     * `texto` livre subiria para `SerproException::getMessage()` — e de lá para o
     * log. É o mesmo vazamento do teste anterior, por um caminho que ninguém
     * exercise porque nenhuma resposta real tem essa forma. O sinal é o
     * `status` de chave: o envelope sempre o traz, o gateway nunca traz
     * envelope.
     */
    public function test_um_corpo_sem_envelope_nao_traz_mensagens_para_o_codigo_nem_para_a_mensagem(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'code' => '900807',
                'message' => 'Throttled out',
                'mensagens' => [[
                    'codigo' => 'AcessoNegado-ICGERENCIADOR-041',
                    'texto' => 'Bearer access-1 recusado para o contribuinte 33683111000107.',
                ]],
            ], 429),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um erro de gateway deve levantar SerproException.');
        } catch (SerproException $exception) {
            // Sem envelope, o código é o do gateway — o `900807`, que classifica
            // `Throttled` — e não o `AcessoNegado` de lá, que reclassificaria a
            // mesma resposta como `Reauthenticate`.
            $this->assertSame(SerproFailure::Throttled, $exception->failure);
            $this->assertSame('900807', $exception->providerCode);
            $this->assertSame(SerproFailure::Throttled->label(), $exception->getMessage());
            $this->assertStringNotContainsString('access-1', $exception->getMessage());
            $this->assertStringNotContainsString('33683111000107', $exception->getMessage());
        }
    }

    public function test_um_codigo_de_gateway_que_nao_e_texto_e_ignorado(): void
    {
        $this->connection();
        $this->fakeTokens();
        $this->cacheTokenPair();

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'code' => ['900807'],
                'message' => 'Message throttled out',
            ], 429),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um erro de gateway deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Throttled, $exception->failure);
            $this->assertNull($exception->providerCode);
            $this->assertSame(SerproFailure::Throttled->label(), $exception->getMessage());
        }
    }
}
