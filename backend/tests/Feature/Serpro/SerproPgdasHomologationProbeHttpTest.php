<?php

namespace Tests\Feature\Serpro;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Services\SerproPgdasHomologationProbe;
use App\Services\SerproTokenPair;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `SerproPgdasHomologationProbe::run()` com `Http::fake` — suíte padrão, sem rede.
 */
class SerproPgdasHomologationProbeHttpTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY = '30288513000100';

    private const OFFICE = '48123272000105';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        Cache::flush();
        Http::preventStrayRequests();

        config([
            'fiscal.environment' => 'homologacao',
            'serpro_probes.enabled' => true,
            'serpro_probes.homologation_canary_cnpj' => self::CANARY,
            'serpro_probes.homologation_account_cnpj' => self::OFFICE,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_sem_conta_canario_da_skip_sem_http(): void
    {
        $report = resolve(SerproPgdasHomologationProbe::class)->run();

        $this->assertSame('skip', $report['outcome']);
        $this->assertSame('conta_nao_encontrada', $report['reason']);
        Http::assertNothingSent();
    }

    public function test_passa_com_fixture_e_projeta_periodos(): void
    {
        [$account] = $this->cenarioCanario();

        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/serpro/pgdasd-consultar-declaracao.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response($fixture),
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $report = resolve(SerproPgdasHomologationProbe::class)->run(
            $account->getKey(),
            self::CANARY,
            2018,
        );

        $this->assertSame('pass', $report['outcome']);
        $this->assertSame('pass', $report['steps']['projecao']['status']);
        $this->assertStringContainsString('1 período(s), 1 com DAS emitido', $report['steps']['projecao']['detail']);
        $this->assertArrayHasKey('projection', $report);
        $this->assertNotEmpty($report['projection']['periods'] ?? null);
        Http::assertSentCount(1);
    }

    public function test_http_429_da_skip_por_throttle(): void
    {
        [$account] = $this->cenarioCanario();

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response([
                'code' => '900807',
                'message' => 'Message throttled out',
            ], 429),
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $report = resolve(SerproPgdasHomologationProbe::class)->run($account->getKey(), self::CANARY, 2018);

        $this->assertSame('skip', $report['outcome']);
        $this->assertSame('provedor', $report['reason']);
        $this->assertSame('skip', $report['steps']['consulta']['status']);
        $this->assertStringContainsString('900807', $report['steps']['consulta']['detail']);
    }

    public function test_codigo_900807_no_envelope_da_skip(): void
    {
        [$account] = $this->cenarioCanario();

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 400,
                'dados' => null,
                'mensagens' => [['codigo' => '900807', 'texto' => 'Cota excedida.']],
            ], 400),
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $report = resolve(SerproPgdasHomologationProbe::class)->run($account->getKey(), self::CANARY, 2018);

        $this->assertSame('skip', $report['outcome']);
        $this->assertSame('provedor', $report['reason']);
        $this->assertStringContainsString('900807', $report['steps']['consulta']['detail']);
    }

    public function test_sem_procuracao_00146_da_skip_legivel(): void
    {
        [$account] = $this->cenarioCanario(comProcuracao: false);

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response(['status' => 200, 'dados' => '{}', 'mensagens' => []]),
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $report = resolve(SerproPgdasHomologationProbe::class)->run($account->getKey(), self::CANARY, 2018);

        $this->assertSame('skip', $report['outcome']);
        $this->assertSame('sem_procuracao', $report['reason']);
        $this->assertSame('skip', $report['steps']['elegibilidade']['status']);
        $this->assertStringContainsString('00146', $report['steps']['elegibilidade']['detail']);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: Account}
     */
    private function cenarioCanario(bool $comProcuracao = true): array
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

        SerproConnection::factory()->create([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
            'certificate_encrypted' => Crypt::encryptString($this->pfxDaPlataforma()),
            'certificate_password_encrypted' => Crypt::encryptString('senha'),
            'contratante_numero' => self::OFFICE,
            'contratante_tipo' => 2,
            'certificate_valid_until' => now()->addYear(),
        ]);

        SerproAuthorizationTerm::forceCreate([
            'account_id' => $account->getKey(),
            'author_document' => self::OFFICE,
            'document_encrypted' => Crypt::encryptString('<termo/>'),
            'document_expires_on' => '2027-01-01',
            'signed_at' => now(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('token-do-termo'),
            'token_expires_at' => CarbonImmutable::parse('2026-09-29'),
        ]);

        AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => self::OFFICE,
        ]);

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => self::CANARY,
        ]);

        if ($comProcuracao) {
            SerproClientAuthorization::factory()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
                'family' => '00146',
                'code' => '00146',
                'state' => SerproPowerOfAttorneyState::Established,
                'expires_on' => '2027-01-01',
            ]);
        }

        return [$account];
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
            ['CN' => 'G A CONT LTDA:'.self::OFFICE, 'serialNumber' => self::OFFICE],
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
