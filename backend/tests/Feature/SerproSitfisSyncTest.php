<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproCall;
use App\Models\SerproConnection;
use App\Models\SerproSyncRun;
use App\Services\SerproEnvelope;
use App\Services\SerproException;
use App\Services\SerproSitfisSequence;
use App\Services\SerproTokenPair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sequência SITFIS: protocolo gratuito, emissão cobrada e polling documentado
 * — sem rede real, só `Http::fake`.
 */
class SerproSitfisSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PROTOCOLO = 'proto-de-teste-base64';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_a_sequencia_solicita_protocolo_emite_com_o_mesmo_protocolo_e_audita_os_dois_passos(): void
    {
        [$account, $client, $run] = $this->cenario();
        $pdf = base64_encode('%PDF-1.4 (relatorio ok)');

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            'gateway.apiserpro.serpro.gov.br/*' => function (Request $request) use ($pdf) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                if ($servico === 'SOLICITARPROTOCOLO91') {
                    return Http::response([
                        'status' => 200,
                        'dados' => json_encode([
                            'protocoloRelatorio' => self::PROTOCOLO,
                            'tempoEspera' => 0,
                        ]),
                        'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Protocolo emitido.']],
                        'responseId' => 'resp-protocolo',
                    ]);
                }

                if ($servico === 'RELATORIOSITFIS92') {
                    return Http::response([
                        'status' => 200,
                        'dados' => json_encode(['pdf' => $pdf]),
                        'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Relatório pronto.']],
                        'responseId' => 'resp-relatorio',
                    ]);
                }

                return Http::response(['status' => 404], 404);
            },
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 600);

        $result = resolve(SerproSitfisSequence::class)->fetch(
            $run->getKey(),
            $account->getKey(),
            $client->getKey(),
            '12345678000195',
            (string) $client->tax_id,
            'token-procurador',
        );

        $this->assertSame(['pdf' => $pdf], $result->dados());

        $calls = SerproCall::query()->orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertSame('SOLICITARPROTOCOLO91', $calls[0]->id_servico);
        $this->assertFalse($calls[0]->billable);
        $this->assertSame('Apoiar', $calls[0]->path);
        $this->assertSame('RELATORIOSITFIS92', $calls[1]->id_servico);
        $this->assertTrue($calls[1]->billable);
        $this->assertSame('Emitir', $calls[1]->path);

        Http::assertSent(function (Request $request): bool {
            if (($request->data()['pedidoDados']['idServico'] ?? null) !== 'RELATORIOSITFIS92') {
                return true;
            }

            $dados = json_decode((string) $request->data()['pedidoDados']['dados'], true);

            return ($dados['protocoloRelatorio'] ?? null) === self::PROTOCOLO;
        });
    }

    public function test_a_emissao_repete_apos_202_e_204_antes_do_pdf(): void
    {
        [$account, $client, $run] = $this->cenario();
        $pdf = base64_encode('%PDF-1.4 ok');
        $tentativasEmitir = 0;

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            'gateway.apiserpro.serpro.gov.br/*' => function (Request $request) use ($pdf, &$tentativasEmitir) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                if ($servico === 'SOLICITARPROTOCOLO91') {
                    return Http::response([
                        'status' => 200,
                        'dados' => json_encode(['protocoloRelatorio' => self::PROTOCOLO, 'tempoEspera' => 0]),
                        'mensagens' => [],
                        'responseId' => 'resp-protocolo',
                    ]);
                }

                $tentativasEmitir++;

                if ($tentativasEmitir === 1) {
                    return Http::response([
                        'status' => 202,
                        'dados' => json_encode(['tempoEspera' => 0]),
                        'mensagens' => [],
                        'responseId' => 'resp-aguarde',
                    ]);
                }

                if ($tentativasEmitir === 2) {
                    return Http::response([
                        'status' => 204,
                        'dados' => null,
                        'mensagens' => [['codigo' => 'Aguardando', 'texto' => 'Processando.']],
                    ], 204);
                }

                return Http::response([
                    'status' => 200,
                    'dados' => json_encode(['pdf' => $pdf]),
                    'mensagens' => [],
                    'responseId' => 'resp-relatorio',
                ]);
            },
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 600);

        resolve(SerproSitfisSequence::class)->fetch(
            $run->getKey(),
            $account->getKey(),
            $client->getKey(),
            '12345678000195',
            (string) $client->tax_id,
            'token-procurador',
        );

        $this->assertSame(3, $tentativasEmitir);
        $this->assertSame(1, SerproCall::query()->where('id_servico', 'RELATORIOSITFIS92')->count());
    }

    public function test_protocolo_vazio_nao_tenta_emitir(): void
    {
        [$account, $client, $run] = $this->cenario();

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 200,
                'dados' => json_encode(['protocoloRelatorio' => '', 'tempoEspera' => 0]),
                'mensagens' => [],
                'responseId' => 'resp-vazio',
            ]),
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 600);

        try {
            resolve(SerproSitfisSequence::class)->fetch(
                $run->getKey(),
                $account->getKey(),
                $client->getKey(),
                '12345678000195',
                (string) $client->tax_id,
                'token-procurador',
            );
            $this->fail('Protocolo vazio devia levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
        }

        $this->assertSame(1, SerproCall::count());
        Http::assertSentCount(1);
    }

    public function test_resposta_200_sem_pdf_nao_conclui_a_obrigacao(): void
    {
        [$account, $client, $run] = $this->cenario();

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            'gateway.apiserpro.serpro.gov.br/*' => function (Request $request) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                if ($servico === 'SOLICITARPROTOCOLO91') {
                    return Http::response([
                        'status' => 200,
                        'dados' => json_encode(['protocoloRelatorio' => self::PROTOCOLO, 'tempoEspera' => 0]),
                        'mensagens' => [],
                        'responseId' => 'resp-protocolo',
                    ]);
                }

                return Http::response([
                    'status' => 200,
                    'dados' => json_encode(['pdf' => '']),
                    'mensagens' => [],
                    'responseId' => 'resp-sem-pdf',
                ]);
            },
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 600);

        try {
            resolve(SerproSitfisSequence::class)->fetch(
                $run->getKey(),
                $account->getKey(),
                $client->getKey(),
                '12345678000195',
                (string) $client->tax_id,
                'token-procurador',
            );
            $this->fail('PDF vazio devia levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
        }

        $this->assertSame(0, SerproCall::query()->where('id_servico', 'RELATORIOSITFIS92')->count());
        $this->assertSame(1, SerproCall::query()->where('id_servico', 'SOLICITARPROTOCOLO91')->count());
    }

    public function test_protocolo_expirado_na_emissao_registra_falha_cobrada(): void
    {
        [$account, $client, $run] = $this->cenario();

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            'gateway.apiserpro.serpro.gov.br/*' => function (Request $request) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                if ($servico === 'SOLICITARPROTOCOLO91') {
                    return Http::response([
                        'status' => 200,
                        'dados' => json_encode(['protocoloRelatorio' => self::PROTOCOLO, 'tempoEspera' => 0]),
                        'mensagens' => [],
                        'responseId' => 'resp-protocolo',
                    ]);
                }

                return Http::response([
                    'status' => 403,
                    'dados' => null,
                    'mensagens' => [[
                        'codigo' => 'Erro-Sitfis-012',
                        'texto' => 'Protocolo expirado ou inválido.',
                    ]],
                    'responseId' => 'resp-expirado',
                ], 403);
            },
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 600);

        try {
            resolve(SerproSitfisSequence::class)->fetch(
                $run->getKey(),
                $account->getKey(),
                $client->getKey(),
                '12345678000195',
                (string) $client->tax_id,
                'token-procurador',
            );
            $this->fail('Protocolo expirado devia levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertSame('Erro-Sitfis-012', $exception->providerCode);
        }

        $falha = SerproCall::query()->where('id_servico', 'RELATORIOSITFIS92')->sole();
        $this->assertSame(SerproFailure::DoNotRetry, $falha->status);
        $this->assertTrue($falha->billable);
    }

    public function test_a_fixture_gravada_continua_parseavel_pelo_envelope(): void
    {
        $protocolo = (new SerproEnvelope)->parse($this->fixture('sitfis-solicitar-protocolo.json'));
        $relatorio = (new SerproEnvelope)->parse($this->fixture('sitfis-relatorio.json'));

        $this->assertNotSame('', trim((string) ($protocolo['dados']['protocoloRelatorio'] ?? '')));
        $this->assertNotSame('', (string) ($relatorio['dados']['pdf'] ?? ''));
    }

    /**
     * @return array{0: Account, 1: Client, 2: SerproSyncRun}
     */
    private function cenario(): array
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

        SerproConnection::factory()->create([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
            'certificate_encrypted' => Crypt::encryptString($this->pfxDaPlataforma()),
            'certificate_password_encrypted' => Crypt::encryptString('senha'),
            'contratante_numero' => '12345678000195',
            'contratante_tipo' => 2,
            'certificate_valid_until' => now()->addYear(),
        ]);

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        return [$account, $client, $run];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $nome): array
    {
        $caminho = base_path('tests/Fixtures/serpro/'.$nome);
        $payload = json_decode((string) file_get_contents($caminho), true);

        return is_array($payload) ? $payload : [];
    }

    private function pfxDaPlataforma(): string
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
}
