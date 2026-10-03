<?php

namespace Tests\Feature;

use App\Console\Commands\SerproProbePgdas;
use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Services\SerproTokenPair;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerproProbePgdasCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY = '30288513000100';

    private const OFFICE = '48123272000105';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        config(['fiscal.environment' => 'homologacao']);
        config(['serpro_probes.enabled' => true]);
        config(['serpro_probes.homologation_canary_cnpj' => self::CANARY]);
        config(['serpro_probes.homologation_account_cnpj' => self::OFFICE]);
        Http::preventStrayRequests();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_recusa_iniciar_fora_de_homologacao(): void
    {
        config(['fiscal.environment' => 'producao']);

        $exit = Artisan::call('serpro:probe-pgdas');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('homologação', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_skip_sem_credencial_de_plataforma(): void
    {
        $exit = Artisan::call('serpro:probe-pgdas');

        $this->assertSame(SerproProbePgdas::EXIT_SKIP, $exit);
        $this->assertStringContainsString('Credencial', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_passa_com_resposta_falsa_e_projeta_periodos(): void
    {
        [$account] = $this->cenarioCanario();

        $pgdas = json_encode([
            'anocalendario' => 2018,
            'periodos' => [[
                'periodoApuracao' => 201801,
                'operacoes' => [[
                    'tipoOperacao' => 'Geração de DAS',
                    'indiceDas' => [
                        'numeroDas' => '07202215764027873',
                        'datahoraEmissaoDas' => '20220606153456',
                        'dasPago' => false,
                    ],
                ]],
            ]],
        ]);

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => $pgdas,
                'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'ok']],
                'responseId' => 'resp-pgdas',
            ]),
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $exit = Artisan::call('serpro:probe-pgdas', ['--account' => $account->getKey()]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Outcome: pass', Artisan::output());
        Http::assertSentCount(1);
    }

    public function test_throttle_vira_skip_legivel(): void
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

        $exit = Artisan::call('serpro:probe-pgdas', ['--account' => $account->getKey()]);

        $this->assertSame(SerproProbePgdas::EXIT_SKIP, $exit);
        $this->assertStringContainsString('throttle', strtolower(Artisan::output()));
    }

    /**
     * @return array{0: Account}
     */
    private function cenarioCanario(): array
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

        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00146',
            'code' => '00146',
            'state' => SerproPowerOfAttorneyState::Established,
            'expires_on' => '2027-01-01',
        ]);

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
