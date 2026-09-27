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
        return SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
            'contratante_numero' => '12345678000195',
            'contratante_tipo' => 2,
        ]);
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
}
