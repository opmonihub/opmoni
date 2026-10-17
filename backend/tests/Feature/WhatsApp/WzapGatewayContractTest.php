<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Services\WhatsApp\WzapClient;
use App\Services\WhatsApp\WzapException;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BusinessTestCase;

class WzapGatewayContractTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $connection = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection->getPdo()->exec(DB::connection()->selectOne("SELECT sql FROM sqlite_master WHERE name = 'external_effect_journal'")->sql);
        $this->app->instance(ExternalEffectJournalConnection::class, new ExternalEffectJournalConnection(fn () => $connection));
        resolve(CurrentTenant::class)->accountId = null;
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.wzap.base_url', 'http://wzap.test');
        config()->set('whatsapp.wzap.api_key', 'chave-global');
        config()->set('whatsapp.wzap.webhook_url', 'https://opmoni.test/api/v1/webhooks/wzap');
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_criacao_real_pareia_com_nome_da_account_e_webhook_aninhado(): void
    {
        $account = Account::factory()->create(['name' => 'Escritório São José']);
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'instance' => $this->gatewayInstance('inst-1', 'opmoni-account-'.$account->getKey()),
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => ['connection' => [
                'status' => 'pairing', 'qr_code' => '2@qr-real', 'qr_expires_at' => '2026-10-08T15:00:00Z',
            ]]]),
        ]);

        $this->actingAs($this->memberOf($account), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')->assertOk()
            ->assertJsonPath('data.status', 'pairing')->assertJsonPath('data.qr_code', '2@qr-real');

        $channel = WhatsAppChannel::forAccount($account->getKey());
        $this->assertSame('inst-1', $channel?->wzap_instance_id);
        $this->assertSame('chave-instancia', $channel?->instanceApiKey());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://wzap.test/instances'
            && $request['name'] === 'opmoni-account-'.$account->getKey()
            && $request['external_ref'] === (string) $account->getKey()
            && $request['webhook'] === [
                'url' => 'https://opmoni.test/api/v1/webhooks/wzap',
                'enabled' => true,
                'events' => ['receipt', 'connection', 'message.status'],
            ]);
    }

    public function test_status_real_recupera_qr_aninhado_para_admin(): void
    {
        [$account, $channel] = $this->channel();
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => ['connection' => ['status' => 'pairing']]]),
            'wzap.test/instances/inst-1/qr' => Http::response(['data' => ['connection' => [
                'status' => 'pairing', 'qr_code' => '2@qr-real', 'qr_expires_at' => '2026-10-08T15:00:00.000Z',
            ]]]),
        ]);

        $admin = $this->memberOf($account);
        foreach (['/api/v1/whatsapp/channel', '/api/v1/whatsapp/channel/pairing'] as $route) {
            $this->actingAs($admin, 'web')->getJson($route)->assertOk()
                ->assertJsonPath('data.status', 'pairing')->assertJsonPath('data.qr_code', '2@qr-real');
        }
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
    }

    public function test_erro_estruturado_real_e_redigido_e_persistido_para_leitura_offline(): void
    {
        [$account, $channel] = $this->channel();
        Http::fake(['wzap.test/instances/inst-1/status' => Http::sequence()
            ->push(['data' => ['connection' => [
                'status' => 'error',
                'last_error' => [
                    'code' => 'connection_failed',
                    'message' => 'credenciais recusadas: chave-instancia e chave-global',
                    'occurred_at' => '2026-10-08T12:00:00Z',
                ],
            ]]])
            ->push(['error' => ['code' => 'unavailable', 'message' => 'indisponível']], 503),
        ]);

        $member = $this->memberOf($account, 'admin');
        foreach ([1, 2] as $attempt) {
            $this->actingAs($member, 'web')->getJson('/api/v1/whatsapp/channel')->assertOk()
                ->assertJsonPath('data.last_error', 'credenciais recusadas: [redigido] e [redigido]');
        }
        $this->assertSame('credenciais recusadas: [redigido] e [redigido]', $channel->refresh()->last_error);
    }

    public function test_conflito_reutiliza_id_ja_vinculado_sem_escolher_instancia_alheia(): void
    {
        [$account, $channel] = $this->channel();
        Http::fake([
            'wzap.test/instances' => Http::response(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409),
            'wzap.test/instances/inst-1' => Http::response(['data' => ['instance' => $this->gatewayInstance('inst-1', 'opmoni-account-'.$account->getKey())]]),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => ['connection' => ['status' => 'connected']]]),
        ]);

        $this->actingAs($this->memberOf($account), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')->assertOk()->assertJsonPath('data.status', 'connected');

        $this->assertSame('inst-1', $channel->refresh()->wzap_instance_id);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_conflito_sem_canal_recupera_somente_nome_deterministico_na_lista_real(): void
    {
        $account = Account::factory()->create(['name' => 'Escritório']);
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        Http::fake(fn (Request $request) => match (true) {
            $request->method() === 'POST' && $request->url() === 'http://wzap.test/instances' => Http::response(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409),
            $request->method() === 'GET' && str_starts_with($request->url(), 'http://wzap.test/instances?') => Http::response(['data' => ['instances' => [
                $this->gatewayInstance('inst-alheia', 'Escritorio'),
                $this->gatewayInstance('inst-1', 'opmoni-account-'.$account->getKey()),
            ]]]),
            $request->method() === 'PATCH' && $request->url() === 'http://wzap.test/instances/inst-1' => Http::response(['data' => ['instance' => $this->gatewayInstance('inst-1', 'opmoni-account-'.$account->getKey())]]),
            $request->url() === 'http://wzap.test/instances/inst-1/apikey/rotate' => Http::response(['data' => ['id' => 'inst-1', 'instance_api_key' => 'chave-instancia']]),
            $request->url() === 'http://wzap.test/instances/inst-1/connect' => Http::response(['data' => ['connection' => ['status' => 'connected']]]),
            default => null,
        });

        $this->actingAs($this->memberOf($account), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')->assertOk()->assertJsonPath('data.status', 'connected');

        $this->assertSame('inst-1', WhatsAppChannel::forAccount($account->getKey())?->wzap_instance_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'inst-alheia/'));
    }

    public function test_conflito_sem_nome_deterministico_nao_aproveita_primeira_instancia(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        Http::fake(['wzap.test/instances*' => Http::sequence()
            ->push(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409)
            ->push(['data' => ['instances' => [$this->gatewayInstance('inst-alheia', 'outro-account')]]]),
        ]);

        $response = $this->actingAs($this->memberOf($account), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')->assertStatus(409)
            ->assertJsonPath('code', 'operation_manual_review_required')
            ->assertJsonPath('next_step', 'whatsapp.instance.create');

        $effects = resolve(ExternalEffectJournal::class)->forResource($account->getKey(), WhatsAppChannelEffects::resource($account->getKey()));
        $this->assertCount(1, $effects);
        $this->assertSame($response->json('operation_id'), $effects[0]['operation_id']);
        $this->assertSame('whatsapp.instance.create', $effects[0]['step']);
        $this->assertSame('confirmed', $effects[0]['state']);
        $this->assertSame('not_applied', $effects[0]['receipt']);
        $this->assertSame(['http_status' => 409], $effects[0]['payload']);
        $this->assertNull(WhatsAppChannel::forAccount($account->getKey()));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/connect'));
    }

    public function test_falha_de_transporte_na_leitura_preserva_espelho_local(): void
    {
        [$account, $channel] = $this->channel();
        Http::fake(['*' => Http::failedConnection('recusa para chave-instancia e chave-global')]);

        $this->actingAs($this->memberOf($account), 'web')
            ->getJson('/api/v1/whatsapp/channel')->assertOk()->assertJsonPath('data.status', 'pairing');

        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
        $this->assertNull($channel->last_error);
    }

    #[DataProvider('gatewayMutations')]
    public function test_falha_de_transporte_preserva_mutacao_incerta_sem_expor_credenciais(string $method, string $route, string $step): void
    {
        [$account, $channel] = $this->channel();
        config()->set('whatsapp.wzap.webhook_url', null);
        Http::fake(['*' => Http::failedConnection('recusa para chave-instancia e chave-global')]);

        $response = $this->actingAs($this->memberOf($account), 'web')->json($method, $route)->assertStatus(409)
            ->assertJsonPath('code', 'operation_reconciliation_required')
            ->assertJsonPath('next_step', $step);

        $this->assertStringNotContainsString('chave-instancia', $response->getContent());
        $this->assertStringNotContainsString('chave-global', $response->getContent());
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
        $this->assertNull($channel->last_error);
        $this->assertUncertainEffect($account, $response->json('operation_id'), $step);
        $this->postJson('/api/v1/whatsapp/channel/operations/'.$response->json('operation_id').'/continue')->assertStatus(409)
            ->assertJsonPath('code', 'operation_reconciliation_required');
        $offline = $this->getJson('/api/v1/whatsapp/channel')->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.last_error', null)
            ->assertJsonPath('data.operation.code', 'operation_reconciliation_required')
            ->assertJsonPath('data.operation.operation_id', $response->json('operation_id'));
        $this->assertStringNotContainsString('chave-instancia', $offline->getContent());
        $this->assertStringNotContainsString('chave-global', $offline->getContent());
        Http::assertSentCount(1);
    }

    public static function gatewayMutations(): array
    {
        return [
            'início' => ['POST', '/api/v1/whatsapp/channel/pairing', 'whatsapp.connect'],
            'desconexão' => ['DELETE', '/api/v1/whatsapp/channel', 'whatsapp.disconnect'],
        ];
    }

    public function test_falha_de_transporte_no_polling_retorna_502_sem_expor_credenciais(): void
    {
        [$account, $channel] = $this->channel();
        Http::fake(['*' => Http::failedConnection('recusa para chave-instancia e chave-global')]);

        $response = $this->actingAs($this->memberOf($account), 'web')->getJson('/api/v1/whatsapp/channel/pairing')->assertStatus(502);

        $this->assertStringNotContainsString('chave-instancia', $response->getContent());
        $this->assertStringNotContainsString('chave-global', $response->getContent());
        $this->assertSame(WhatsAppChannel::STATUS_ERROR, $channel->refresh()->status);
        $this->assertNotEmpty($channel->last_error);
        $this->assertSame([], resolve(ExternalEffectJournal::class)->forResource($account->getKey(), WhatsAppChannelEffects::resource($account->getKey())));
    }

    #[DataProvider('invalidQr')]
    public function test_campos_invalidos_do_qr_nao_contaminam_status(mixed $code, mixed $expiresAt): void
    {
        [$account, $channel] = $this->channel();
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => ['status' => 'pairing']]),
            'wzap.test/instances/inst-1/qr' => Http::response(['data' => ['qr_code' => $code, 'qr_expires_at' => $expiresAt]]),
        ]);

        $this->actingAs($this->memberOf($account), 'web')
            ->getJson('/api/v1/whatsapp/channel/pairing')->assertOk()
            ->assertJsonPath('data.status', 'pairing')->assertJsonPath('data.qr_code', null)->assertJsonPath('data.qr_expires_at', null);

        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
    }

    public static function invalidQr(): array
    {
        return [
            'código composto' => [[], '2026-10-08T15:00:00Z'],
            'código vazio' => ['', '2026-10-08T15:00:00Z'],
            'expiração composta' => ['2@qr', []],
            'expiração inválida' => ['2@qr', 'data-inválida'],
            'dia impossível' => ['2@qr', '2026-02-31T15:00:00Z'],
        ];
    }

    public function test_envio_real_normaliza_identificador_da_mensagem_para_o_rastreio(): void
    {
        Http::fake(['wzap.test/instances/inst-1/messages/media' => Http::response(['data' => ['message' => [
            'id' => 'msg-1', 'instance_id' => 'inst-1', 'send_status' => 'queued', 'media_id' => 'media-1',
        ]]], 202)]);

        $result = resolve(WzapClient::class)->sendMedia('inst-1', 'chave-instancia', 'pdf', 'guia.pdf', 'application/pdf', '5511999999999', 'idem-1');

        $this->assertSame('msg-1', $result['message_id'] ?? null);
        $this->assertSame('queued', $result['status'] ?? null);
    }

    public function test_criacao_sem_identificador_retorna_falha_controlada(): void
    {
        Http::fake(['wzap.test/instances' => Http::response(['data' => ['instance' => ['name' => 'opmoni-account-1'], 'instance_api_key' => 'chave-instancia']], 201)]);

        $this->expectException(WzapException::class);

        resolve(WzapClient::class)->createInstance('1', 'opmoni-account-1');
    }

    public function test_instancia_reutilizada_recebe_webhook_corrente_antes_de_parear(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409),
            'wzap.test/instances/inst-1' => Http::response(['data' => ['instance' => $this->gatewayInstance('inst-1', 'opmoni-account-1')]]),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => ['connection' => ['status' => 'connected']]]),
        ]);

        $client = resolve(WzapClient::class);
        $instance = $client->ensureInstance('1', 'opmoni-account-1', 'inst-1');
        $this->assertSame('connected', $client->connect($instance['id'], 'chave-instancia')['status']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://wzap.test/instances/inst-1'
            && ($request->header('apikey')[0] ?? null) === 'chave-global'
            && $request['webhook'] === [
                'url' => 'https://opmoni.test/api/v1/webhooks/wzap',
                'enabled' => true,
                'events' => ['receipt', 'connection', 'message.status'],
            ]);
    }

    public function test_falha_ao_restaurar_webhook_impede_pareamento_e_exige_revisao_sem_expor_motivo(): void
    {
        [$account, $channel] = $this->channel();
        Http::fake([
            'wzap.test/instances' => Http::response(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409),
            'wzap.test/instances/inst-1' => Http::response(['error' => [
                'code' => 'unauthorized', 'message' => 'credenciais recusadas: chave-instancia e chave-global',
            ]], 401),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => ['connection' => ['status' => 'connected']]]),
        ]);

        $response = $this->actingAs($this->memberOf($account), 'web')->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertStatus(409)
            ->assertJsonPath('code', 'operation_manual_review_required')
            ->assertJsonPath('next_step', 'whatsapp.webhook');

        $this->assertStringNotContainsString('chave-instancia', $response->getContent());
        $this->assertStringNotContainsString('chave-global', $response->getContent());
        $this->assertStringNotContainsString('credenciais recusadas', $response->getContent());
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
        $this->assertNull($channel->last_error);
        $this->assertUncertainEffect($account, $response->json('operation_id'), 'whatsapp.webhook');
        $this->getJson('/api/v1/whatsapp/channel')->assertOk()
            ->assertJsonPath('data.status', 'pairing')
            ->assertJsonPath('data.last_error', null)
            ->assertJsonPath('data.operation.code', 'operation_manual_review_required');
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/connect'));
    }

    public function test_pareamento_com_erro_estruturado_grava_motivo_sanitizado(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'instance' => $this->gatewayInstance('inst-1', 'opmoni-account-'.$account->getKey()),
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => ['connection' => [
                'status' => 'error', 'last_error' => ['code' => 'refused', 'message' => 'recusa chave-instancia e chave-global'],
            ]]]),
        ]);

        $this->actingAs($this->memberOf($account), 'web')->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk()->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.last_error', 'recusa [redigido] e [redigido]');

        $this->assertSame('recusa [redigido] e [redigido]', WhatsAppChannel::forAccount($account->getKey())?->last_error);
    }

    public function test_status_real_com_estado_composto_preserva_espelho_local(): void
    {
        [$account, $channel] = $this->channel();
        Http::fake(['wzap.test/instances/inst-1/status' => Http::response(['data' => ['connection' => ['status' => []]]])]);

        $this->actingAs($this->memberOf($account), 'web')->getJson('/api/v1/whatsapp/channel')
            ->assertOk()->assertJsonPath('data.status', 'pairing');

        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
    }

    public function test_erro_http_do_cliente_nao_ecoa_credenciais_da_instancia_e_plataforma(): void
    {
        Http::fake(['wzap.test/instances/inst-1/connect' => Http::response(['error' => [
            'code' => 'unauthorized', 'message' => 'recusa chave-instancia e chave-global',
        ]], 401)]);

        try {
            resolve(WzapClient::class)->connect('inst-1', 'chave-instancia');
            $this->fail('O gateway recusou a autenticação e deveria produzir WzapException.');
        } catch (WzapException $exception) {
            $this->assertSame(401, $exception->status());
            $this->assertSame('unauthorized', $exception->errorCode());
            $this->assertSame('wzap unauthorized: recusa [redigido] e [redigido]', $exception->getMessage());
            $this->assertStringNotContainsString('chave-instancia', $exception->getMessage());
            $this->assertStringNotContainsString('chave-global', $exception->getMessage());
        }
    }

    public function test_instancia_reutilizada_sem_url_configurada_nao_atualiza_webhook(): void
    {
        config()->set('whatsapp.wzap.webhook_url', null);
        Http::fake(['wzap.test/instances' => Http::response(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409)]);

        $instance = resolve(WzapClient::class)->ensureInstance('1', 'opmoni-account-1', 'inst-1');

        $this->assertSame('inst-1', $instance['id']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_falha_http_ao_restaurar_webhook_preserva_erro_redigido_no_cliente(): void
    {
        Http::fake(['wzap.test/instances/inst-1' => Http::response(['error' => [
            'code' => 'unauthorized', 'message' => 'credenciais recusadas: chave-global',
        ]], 401)]);

        try {
            resolve(WzapClient::class)->configureWebhook('inst-1');
            $this->fail('O gateway recusou o webhook e deveria produzir WzapException.');
        } catch (WzapException $exception) {
            $this->assertSame(401, $exception->status());
            $this->assertSame('unauthorized', $exception->errorCode());
            $this->assertSame('wzap unauthorized: credenciais recusadas: [redigido]', $exception->getMessage());
            $this->assertStringNotContainsString('chave-global', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://wzap.test/instances/inst-1');
    }

    public function test_falha_de_transporte_do_cliente_preserva_excecao_sem_expor_credenciais(): void
    {
        Http::fake(['*' => Http::failedConnection('recusa para chave-instancia e chave-global')]);

        try {
            resolve(WzapClient::class)->connect('inst-1', 'chave-instancia');
            $this->fail('A falha de transporte deveria produzir WzapException.');
        } catch (WzapException $exception) {
            $this->assertSame(0, $exception->status());
            $this->assertNull($exception->errorCode());
            $this->assertSame('Não foi possível conectar ao gateway wzap. Tente novamente.', $exception->getMessage());
            $this->assertStringNotContainsString('chave-instancia', $exception->getMessage());
            $this->assertStringNotContainsString('chave-global', $exception->getMessage());
        }
    }

    public function test_fixture_do_journal_usa_pdo_separado_em_autocommit(): void
    {
        $connection = resolve(ExternalEffectJournalConnection::class)->connection();

        $this->assertNotSame(DB::connection()->getPdo(), $connection->getPdo());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('external_effect_journal'));
        $this->assertSame(0, $connection->table('external_effect_journal')->count());
        Http::assertNothingSent();
    }

    private function assertUncertainEffect(Account $account, string $operationId, string $step): void
    {
        $effects = resolve(ExternalEffectJournal::class)->forResource($account->getKey(), WhatsAppChannelEffects::resource($account->getKey()));
        $this->assertCount(1, $effects);
        $this->assertSame($operationId, $effects[0]['operation_id']);
        $this->assertSame($step, $effects[0]['step']);
        $this->assertSame('uncertain', $effects[0]['state']);
        $this->assertNull($effects[0]['receipt']);
        $this->assertSame([], $effects[0]['payload']);
        $this->assertNotNull($effects[0]['armed_at']);
        $this->assertNull($effects[0]['confirmed_at']);
        $this->assertNull(resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->where('operation_id', $operationId)->value('payload_encrypted'));
    }

    private function channel(): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $channel = WhatsAppChannel::factory()->pairing()->create(['account_id' => $account->getKey(), 'wzap_instance_id' => 'inst-1']);
        $channel->storeInstanceApiKey('chave-instancia');

        return [$account, $channel];
    }

    private function gatewayInstance(string $id, string $name): array
    {
        return [
            'id' => $id, 'name' => $name,
            'created_at' => '2026-10-08T12:00:00Z', 'updated_at' => '2026-10-08T12:00:00Z',
            'connection' => ['status' => 'disconnected'],
            'integration' => ['webhook' => ['enabled' => false, 'events' => []]],
        ];
    }

    private function memberOf(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
