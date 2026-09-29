<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproConnection;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A leitura de mensagem da caixa postal é ciência da intimação (D19): toda
 * recusa tem de acontecer antes de `MSGDETALHAMENTO62` sair, e por isso cada
 * caminho de recusa afirma que nada foi enviado ao provedor.
 */
class SerproMailboxReadTest extends TestCase
{
    use RefreshDatabase;

    private const OBRIGACAO = 'caixas-postais/e-cac';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_sem_confirmacao_da_ciencia_recusa_e_nao_chama_o_provedor(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->postJson($this->rota($client, 82838), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ciencia');

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->postJson($this->rota($client, 82838), ['ciencia' => false])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_membro_user_nao_registra_ciencia(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_mensagem_fora_da_caixa_sincronizada_do_cliente_e_404(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson($this->rota($client, 99999), ['ciencia' => true])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_obrigacao_sem_caixa_postal_e_404(): void
    {
        [$account, $client] = $this->cenario();
        $this->linha($client, 'declaracoes/pgdas', [['id' => 82838]]);
        Http::fake();

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson("/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$client->getKey()}/messages/82838", ['ciencia' => true])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_cliente_de_outra_conta_e_404(): void
    {
        [, $client] = $this->cenario();
        $vizinha = Account::factory()->create();
        Http::fake();

        $this->actingAs($this->memberOf($vizinha, 'admin'), 'sanctum')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_com_confirmacao_le_a_mensagem_monta_o_corpo_e_marca_o_stub(): void
    {
        [$account, $client] = $this->cenario();

        Http::fake([
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => json_encode([
                    'codigo' => '00',
                    'conteudo' => [[
                        'isn' => '0000082838',
                        'assuntoModelo' => '[IRPF] Declaração do exercício ++VARIAVEL++ processada',
                        'valorParametroAssunto' => '2026',
                        'numeroControle' => '2026/000000000000001',
                        'dataLeitura' => '20260928',
                        'horaLeitura' => '120000',
                        'dataCiencia' => '20260928',
                        'dataExpiracao' => '20261028',
                        'corpoModelo' => '<p>A declaração ++1++ está em ++2++</p><p>Fim &amp; ok</p>',
                        'variaveis' => ['2026', '<b>e-CAC</b>'],
                    ]],
                ]),
                'mensagens' => [['codigo' => '00', 'texto' => 'Recuperação OK.']],
                'responseId' => 'resp-62',
            ]),
        ]);

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertOk()
            ->assertJsonPath('data.id', 82838)
            ->assertJsonPath('data.codigo', '2026/000000000000001')
            ->assertJsonPath('data.assunto', '[IRPF] Declaração do exercício 2026 processada')
            // A variável entra como texto: a tag dela não vira marcação removida.
            ->assertJsonPath('data.corpo', "A declaração 2026 está em <b>e-CAC</b>\nFim & ok")
            ->assertJsonPath('data.ciencia_em', '2026-09-28')
            ->assertJsonPath('data.prazo_limite', '2026-10-28');

        Http::assertSent(function ($request) use ($client): bool {
            $body = $request->data();

            return ($body['pedidoDados']['idServico'] ?? null) === 'MSGDETALHAMENTO62'
                && ($body['pedidoDados']['idSistema'] ?? null) === 'CAIXAPOSTAL'
                && json_decode($body['pedidoDados']['dados'] ?? '', true) === ['isn' => '0000082838']
                && ($body['contribuinte']['numero'] ?? null) === $client->tax_id;
        });

        $stub = collect(SerproMonitoring::query()->withoutGlobalScope('account')->sole()->messages)->firstWhere('id', 82838);
        $this->assertFalse($stub['unread']);
        $this->assertSame('2026-09-28', $stub['ciencia_em']);

        $call = SerproCall::query()->withoutGlobalScope('account')->sole();
        $this->assertNull($call->run_id);
        $this->assertSame('MSGDETALHAMENTO62', $call->id_servico);
        $this->assertSame($client->getKey(), $call->client_id);
    }

    public function test_falha_do_provedor_responde_o_rotulo_e_nao_o_texto_dele(): void
    {
        [$account, $client] = $this->cenario();

        Http::fake([
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 400,
                'dados' => '',
                'mensagens' => [['codigo' => 'Erro-99', 'texto' => 'texto interno do provedor']],
                'responseId' => 'resp-erro',
            ], 400),
        ]);

        $response = $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Correção necessária');

        $this->assertStringNotContainsString('texto interno do provedor', $response->getContent());

        $stub = collect(SerproMonitoring::query()->withoutGlobalScope('account')->sole()->messages)->firstWhere('id', 82838);
        $this->assertTrue($stub['unread']);
    }

    public function test_a_listagem_nao_le_mensagem_nenhuma(): void
    {
        [$account] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/'.self::OBRIGACAO)
            ->assertOk()
            ->assertJsonPath('data_rows.0.message.id', 82838);

        Http::assertNothingSent();
    }

    /**
     * @return array{0: Account, 1: Client}
     */
    private function cenario(): array
    {
        $account = $this->contaPronta();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        $this->linha($client, self::OBRIGACAO, [[
            'id' => 82838,
            'assunto' => 'Intimação',
            'received_at' => '2026-09-20',
            'lida_em' => null,
            'ciencia_em' => null,
            'prazo_limite' => null,
            'unread' => true,
        ]]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        return [$account, $client];
    }

    private function rota(Client $client, int $isn): string
    {
        return '/api/serpro/monitoring/obligations/'.self::OBRIGACAO."/clients/{$client->getKey()}/messages/{$isn}";
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function linha(Client $client, string $obrigacao, array $messages): SerproMonitoring
    {
        return SerproMonitoring::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'obligation' => $obrigacao,
            'messages' => $messages,
            'source_at' => '2026-09-26 10:00:00',
        ]);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    private function contaPronta(): Account
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

        if (SerproConnection::current() === null) {
            SerproConnection::factory()->create([
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
                'certificate_encrypted' => Crypt::encryptString($this->pfxDaPlataforma()),
                'certificate_password_encrypted' => Crypt::encryptString('senha'),
                'contratante_numero' => '12345678000195',
                'contratante_tipo' => 2,
                'certificate_valid_until' => now()->addYear(),
            ]);
        }

        SerproAuthorizationTerm::forceCreate([
            'account_id' => $account->getKey(),
            'author_document' => '12345678000195',
            'document_encrypted' => Crypt::encryptString('<termo/>'),
            'document_expires_on' => '2027-01-01',
            'signed_at' => now(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('token-do-termo'),
            'token_expires_at' => CarbonImmutable::parse('2026-09-29'),
        ]);

        AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => '12345678000195',
        ]);

        return $account;
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
