<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\ClientEcacPowerOfAttorney;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\SerproTokenPair;
use App\Services\SerproTokenProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SerproConnectivityTest extends TestCase
{
    use RefreshDatabase;

    /** O CNPJ que o certificado de teste carrega; é o mesmo do factory. */
    private const CNPJ = '12345678000195';

    private const SENHA = 'senha-do-pfx';

    private const SEGREDO = 'segredo-da-plataforma';

    /** Só a autenticação é esperada: o gateway nunca entra neste teste. */
    private const AUTHENTICATION = 'autenticacao.sapi.serpro.gov.br/*';

    /**
     * Toda tabela que carrega dado de cliente. A carteira montada em `setUp()`
     * é o que dá sentido à exigência de não tocar em nada: um teste que passa
     * por não ter cliente para tocar não prova nada.
     *
     * @var list<string>
     */
    private const CLIENT_TABLES = [
        'client_certificates',
        'client_ecac_powers_of_attorney',
        'client_saved_filters',
        'client_tag',
        'clients',
        'fiscal_cursors',
        'fiscal_documents',
        'serpro_monitorings',
    ];

    /** @var array<string, list<string>> */
    private array $carteira = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum desvio de rota: uma chamada que não for a autenticação do
        // provedor estoura aqui em vez de passar por um fake largo.
        Http::preventStrayRequests();

        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->create(['client_id' => $client->getKey()]);
        ClientEcacPowerOfAttorney::factory()->create(['client_id' => $client->getKey()]);

        $this->carteira = $this->carteira();

        // Retrato vazio não prova nada: se a carteira de cima sumir do `setUp()`,
        // a comparação do `tearDown()` continua passando e o requisito fica sem
        // testigo.
        $this->assertNotEmpty($this->carteira['clients'], 'A carteira do setUp é o que dá sentido à comparação.');
        $this->assertNotEmpty($this->carteira['client_certificates']);
        $this->assertNotEmpty($this->carteira['client_ecac_powers_of_attorney']);
    }

    protected function tearDown(): void
    {
        // A comparação vive no `tearDown()` para valer mesmo quando o teste
        // falha antes dela; o rollback do `RefreshDatabase` acontece depois, em
        // `parent::tearDown()`.
        $this->assertSame(
            $this->carteira,
            $this->carteira(),
            'O teste de conectividade não pode criar, modificar nem apagar dado de cliente.',
        );

        parent::tearDown();
    }

    public function test_sem_conexao_retorna_configuracao_sem_chamar_provedor(): void
    {
        Http::preventStrayRequests();
        $antes = now();
        $response = $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->postJson('/api/serpro/connectivity');
        $depois = now();

        $response->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'configuracao')
            ->assertJsonPath(
                'data.message',
                'A credencial do Integra Contador não está configurada: faltam a chave de integração ou o segredo.',
            );

        $this->assertCheckedAtIsNow($response, $antes, $depois);

        Http::assertNothingSent();
    }

    public function test_credencial_incompleta_e_configuracao_e_nao_credencial_recusada(): void
    {
        // Chave vazia: o que falta é configuração local. Um operador que lesse
        // "credencial" aqui iria caçar no SERPRO o que está errado na própria
        // tela. O segredo não tem este teste porque a coluna é `NOT NULL` — não
        // existe linha sem segredo guardado, e a entrada que produziria uma nem
        // chega a ser gravada.
        SerproConnection::factory()->create(['consumer_key' => '']);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'configuracao');

        Http::assertNothingSent();
    }

    public function test_certificado_ausente_ou_vencido_e_certificado_sem_chamar_provedor(): void
    {
        // A linha da plataforma sem PFX: nada a autenticar, e nenhuma
        // autenticação recusada para descobrir isso.
        $connection = SerproConnection::factory()->create(['certificate_encrypted' => null]);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'certificado');

        $connection->forceFill(['certificate_valid_until' => now()->subDay()])->save();

        // Certificado guardado e vencido é o mesmo conserto — trocar o
        // certificado — e não o mesmo conserto de "credencial recusada".
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'certificado');

        Http::assertNothingSent();
    }

    public function test_certificado_de_outro_contratante_e_certificado_sem_chamar_provedor(): void
    {
        // O `contratante_numero` guardado não é o do PFX guardado: estado que
        // só uma escrita fora do manager produz, e que o provedor responderia
        // com um `403` indistinguível de senha errada.
        $this->connection(['contratante_numero' => '27865757000102']);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'certificado');

        Http::assertNothingSent();
    }

    public function test_credencial_recusada_com_401_e_credencial(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response(['message' => 'Token recusado.'], 401)]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'credencial')
            ->assertJsonPath('data.message', 'O Integra Contador recusou a credencial configurada.');

        Http::assertSentCount(1);
    }

    public function test_recusa_que_nao_e_401_tambem_e_credencial(): void
    {
        // A autenticação do SERPRO recusa credencial com `400` ("Não foi
        // possível identificar um certificado digital válido") tanto quanto
        // com `401`. As duas são recusa do que foi enviado, e o provedor não
        // autentica uma credencial que ele não reconheceu: tratar as duas como
        // indisponibilidade mandaria o operador esperar por um serviço que está
        // de pé e respondendo.
        Http::fake([self::AUTHENTICATION => Http::response(['message' => 'Certificado inválido.'], 400)]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'credencial');

        Http::assertSentCount(1);
    }

    public function test_erro_do_servidor_e_provedor(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response(['message' => 'Erro interno.'], 503)]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'provedor')
            ->assertJsonPath(
                'data.message',
                'O serviço de autenticação do Integra Contador está indisponível.',
            );

        Http::assertSentCount(1);
    }

    public function test_provedor_inalcancavel_e_provedor(): void
    {
        Http::fake(Http::failedConnection('connection refused'));
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'provedor');

        Http::assertSentCount(1);
    }

    public function test_resposta_sem_o_token_de_autorizacao_e_provedor_e_nao_segue_para_o_gateway(): void
    {
        // O par incompleto é defeito da resposta do provedor, não da
        // credencial: sem o `jwt_token` nenhuma chamada de serviço é possível,
        // e o teste de conectividade não pode virar atalho para o gateway.
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
        ])]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'provedor');

        // Uma única chamada, e para a autenticação: `preventStrayRequests`
        // transformaria uma ida ao gateway em exceção, mas a contagem diz o
        // que a exceção sozinha não diria.
        Http::assertSentCount(1);
    }

    public function test_autenticacao_valida_responde_ok_sem_chamar_o_gateway(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'token_type' => 'Bearer',
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        $antes = now();
        $this->superAdmin();
        $response = $this->postJson('/api/serpro/connectivity');
        $depois = now();

        $response->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.failed_element', null)
            ->assertJsonPath('data.message', null);

        $this->assertCheckedAtIsNow($response, $antes, $depois);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://autenticacao.sapi.serpro.gov.br/');
        });
    }

    public function test_o_par_recem_emitido_fica_em_cache_para_a_execucao_seguinte(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $pair = Cache::get('serpro:token-pair');

        $this->assertInstanceOf(SerproTokenPair::class, $pair);
        $this->assertSame('jwt-1', $pair->jwtToken());

        // Testar a conexão não pode custar uma autenticação à próxima execução
        // de verdade: o par emitido aqui é o que a sincronização reaproveita.
        resolve(SerproTokenProvider::class)->pair();

        Http::assertSentCount(1);
    }

    public function test_o_par_em_cache_nao_e_aceito_como_teste_de_conectividade(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        // Um par em cache é o resto de uma autenticação que já deu certo: quem
        // pergunta "está funcionando agora?" precisa de uma resposta de agora,
        // e não do token de meia hora atrás.
        Cache::put('serpro:token-pair', new SerproTokenPair('access-antigo', 'jwt-antigo', 2008), 600);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        Http::assertSentCount(1);
    }

    public function test_resposta_nao_traz_texto_do_provedor_nem_segredo(): void
    {
        // O provedor pode devolver qualquer coisa num erro, inclusive o eco do
        // que foi enviado. A resposta nomeia o que recaiu sobre quem e nunca
        // repassa esse texto.
        $textoDoProvedor = 'client_credentials 5000012345 / '.self::SEGREDO.' / '.self::SENHA;

        Http::fake([self::AUTHENTICATION => Http::response([
            'message' => $textoDoProvedor,
            'detail' => self::SEGREDO,
        ], 401)]);
        $this->connection();

        $this->superAdmin();
        $response = $this->postJson('/api/serpro/connectivity');
        $content = (string) $response->getContent();

        $response->assertOk()
            ->assertJsonPath('data.failed_element', 'credencial')
            ->assertJsonMissingPath('data.provider_message')
            ->assertJsonMissingPath('data.consumer_secret')
            ->assertJsonMissingPath('data.certificate');

        $this->assertStringNotContainsString($textoDoProvedor, $content);
        $this->assertStringNotContainsString(self::SEGREDO, $content);
        $this->assertStringNotContainsString(self::SENHA, $content);
        $this->assertStringNotContainsString(
            (string) SerproConnection::sole()->getRawOriginal('consumer_secret_encrypted'),
            $content,
        );
    }

    public function test_membro_da_conta_sem_super_admin_recebe_403(): void
    {
        $this->postJson('/api/serpro/connectivity')->assertUnauthorized();

        $this->connection();

        // Admin da conta não é operador da plataforma: a credencial é da
        // plataforma, e `super_admin` é `users.is_super_admin`, não um papel de
        // `account_user.role`. Qualquer um dos três papéis recebe a mesma
        // recusa.
        foreach (['admin', 'operador', 'user'] as $role) {
            $this->actingAs($this->memberOf($role), 'sanctum')
                ->postJson('/api/serpro/connectivity')
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function connection(array $atributos = []): SerproConnection
    {
        $pfx = $this->pfx();

        return SerproConnection::factory()->create(array_merge([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString(self::SEGREDO),
            'certificate_encrypted' => Crypt::encryptString($pfx),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
            'contratante_numero' => self::CNPJ,
            'certificate_valid_until' => now()->addYear(),
        ], $atributos));
    }

    private function superAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum');
    }

    private function memberOf(string $role): User
    {
        $account = Account::factory()->create();
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    /** PFX real, com o CNPJ da coluna de contratante, gerado em runtime. */
    private function pfx(): string
    {
        // `.pfx` é ignorado pelo git: o certificado é gerado em runtime, como
        // em `SerproTokenProviderTest`, e nunca versionado.
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(
            ['CN' => 'SERPRO PLATAFORMA LTDA:'.self::CNPJ, 'serialNumber' => self::CNPJ],
            $key,
            array_merge(['digest_alg' => 'sha256'], $config),
        );
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, self::SENHA));

        return $pfx;
    }

    /**
     * `checked_at` precisa ser o instante da verificação, e não um campo
     * qualquer: é dele que a tela tira o "verificado em".
     */
    private function assertCheckedAtIsNow(TestResponse $response, Carbon $antes, Carbon $depois): void
    {
        $checkedAt = $response->json('data.checked_at');

        $this->assertIsString($checkedAt);
        $this->assertTrue(
            Carbon::parse($checkedAt)->betweenIncluded($antes, $depois),
            "A verificação precisa informar quando foi feita; veio de {$checkedAt}.",
        );
    }

    /**
     * Retrato de todas as linhas de cliente, ordenadas para não depender da
     * ordem em que o banco as devolveu.
     *
     * @return array<string, list<string>>
     */
    private function carteira(): array
    {
        $retrato = [];

        foreach (self::CLIENT_TABLES as $tabela) {
            $linhas = array_map(
                fn (object $linha): string => json_encode((array) $linha, JSON_THROW_ON_ERROR),
                DB::table($tabela)->get()->all(),
            );
            sort($linhas);
            $retrato[$tabela] = $linhas;
        }

        return $retrato;
    }
}
