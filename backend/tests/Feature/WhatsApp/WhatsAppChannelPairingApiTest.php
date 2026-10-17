<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Services\ExternalEffectJournalConnection;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

/**
 * A API do Canal WhatsApp: pareamento e desconexão são do `admin` (e do
 * suporte), a leitura de status é de qualquer Membro, e o estado gravado em
 * `whatsapp_channels` espelha o que o wzap informa.
 *
 * O wzap é falso em todos os testes (`Http::fake`): nenhuma requisição sai
 * para a rede, e cada cenário afirma a chamada que o controller fez.
 */
class WhatsAppChannelPairingApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $connection = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection->getPdo()->exec(DB::connection()->selectOne("SELECT sql FROM sqlite_master WHERE name = 'external_effect_journal'")->sql);
        $this->app->instance(ExternalEffectJournalConnection::class, new ExternalEffectJournalConnection(fn () => $connection));
        resolve(CurrentTenant::class)->accountId = null;

        config()->set('whatsapp.wzap.base_url', 'http://wzap.test');
        config()->set('whatsapp.wzap.api_key', 'chave-global');
        config()->set('whatsapp.wzap.webhook_url', 'https://opmoni.test/api/v1/webhooks/wzap');

        $this->account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $this->account->getKey();
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_admin_inicia_pareamento_e_recebe_qr_com_canal_em_pairing(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'id' => 'inst-1',
                'external_ref' => (string) $this->account->getKey(),
                'status' => 'disconnected',
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => [
                'status' => 'pairing',
                'qr_code' => '2@qr-do-teste',
                'qr_expires_at' => '2026-10-04T12:00:00Z',
            ]]),
        ]);

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk()
            ->assertJsonPath('data.status', 'pairing')
            ->assertJsonPath('data.qr_code', '2@qr-do-teste');

        $channel = WhatsAppChannel::forAccount($this->account->getKey());
        $this->assertNotNull($channel);
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->status);
        $this->assertSame('inst-1', $channel->wzap_instance_id);
        $this->assertSame('chave-instancia', $channel->instanceApiKey());
        $this->assertNotNull($channel->pairing_started_at);

        // A apikey da instância autentica o connect — e nunca sai na resposta.
        Http::assertSent(fn ($request): bool => $request->url() === 'http://wzap.test/instances/inst-1/connect'
            && ($request->header('apikey')[0] ?? null) === 'chave-instancia');
        $this->assertStringNotContainsString('chave-instancia', $response->getContent());
    }

    public function test_pareamento_reutiliza_instancia_existente_e_rotaciona_chave_quando_canal_nao_tem(): void
    {
        // Canal sem segredo: a instância já existe no wzap (external_ref em
        // uso), a listagem não devolve `instance_api_key`, e o controller
        // precisa rotacionar para obter uma chave que funcione.
        WhatsAppChannel::factory()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);

        Http::fake(fn ($request) => match (true) {
            // O POST de criação bate `wzap.test/instances`; o GET da varredura
            // leva `?limit=100` e o `*` cobre os caminhos de instância abaixo
            // — a rotação e o connect têm padrões próprios registrados antes.
            $request->method() === 'POST' && $request->url() === 'http://wzap.test/instances' => Http::response(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409),
            $request->method() === 'GET' && str_starts_with($request->url(), 'http://wzap.test/instances?') => Http::response(['data' => ['items' => [
                ['id' => 'inst-1', 'external_ref' => (string) $this->account->getKey(), 'status' => 'disconnected'],
            ], 'next_cursor' => null]]),
            $request->method() === 'PATCH' && $request->url() === 'http://wzap.test/instances/inst-1' => Http::response(['data' => ['id' => 'inst-1']]),
            $request->method() === 'POST' && $request->url() === 'http://wzap.test/instances/inst-1/apikey/rotate' => Http::response(['data' => ['instance_api_key' => 'chave-rotacionada']]),
            $request->method() === 'POST' && $request->url() === 'http://wzap.test/instances/inst-1/connect' => Http::response(['data' => [
                'status' => 'pairing',
                'qr_code' => '2@qr-do-teste',
                'qr_expires_at' => '2026-10-04T12:00:00Z',
            ]]),
            default => null,
        });

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk()
            ->assertJsonPath('data.status', 'pairing');

        $channel = WhatsAppChannel::forAccount($this->account->getKey());
        $this->assertSame('chave-rotacionada', $channel->instanceApiKey());
    }

    public function test_operador_recebe_403_no_pareamento_e_estado_nao_muda(): void
    {
        $operador = $this->memberOf($this->account, 'operador');

        $this->actingAs($operador, 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertForbidden();

        $this->actingAs($operador, 'web')
            ->deleteJson('/api/v1/whatsapp/channel')
            ->assertForbidden();

        $this->assertNull(WhatsAppChannel::forAccount($this->account->getKey()));
        Http::assertNothingSent();
    }

    public function test_user_recebe_403_no_pareamento(): void
    {
        $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertForbidden();
    }

    public function test_status_do_canal_e_leitura_de_qualquer_membro(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => [
                'status' => 'connected',
                'whatsapp_jid' => '5511999999999@s.whatsapp.net',
                'last_connected_at' => '2026-10-04T10:00:00Z',
            ]]),
        ]);

        WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);

        $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->getJson('/api/v1/whatsapp/channel')
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.whatsapp_jid', '5511999999999@s.whatsapp.net');
    }

    public function test_status_de_conta_sem_canal_responde_404(): void
    {
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson('/api/v1/whatsapp/channel')
            ->assertNotFound();
    }

    public function test_membros_sem_permissao_leem_status_sem_receber_qr(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => ['status' => 'pairing']]),
            'wzap.test/instances/inst-1/qr' => Http::response(['data' => [
                'qr_code' => '2@qr-restrito',
                'qr_expires_at' => '2026-10-08T15:00:00Z',
            ]]),
        ]);

        $channel = WhatsAppChannel::factory()->pairing()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
        $channel->storeInstanceApiKey('chave-instancia');

        foreach (['operador', 'user'] as $role) {
            $member = $this->memberOf($this->account, $role);

            foreach (['/api/v1/whatsapp/channel', '/api/v1/whatsapp/channel/pairing'] as $route) {
                $this->actingAs($member, 'web')->getJson($route)
                    ->assertOk()
                    ->assertJsonPath('data.status', 'pairing')
                    ->assertJsonPath('data.qr_code', null)
                    ->assertJsonPath('data.qr_expires_at', null);
            }
        }

        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/qr'));
    }

    public function test_admin_recupera_qr_ao_reler_canal_em_pareamento(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => ['status' => 'pairing']]),
            'wzap.test/instances/inst-1/qr' => Http::response(['data' => [
                'qr_code' => '2@qr-corrente',
                'qr_expires_at' => '2026-10-08T15:00:00Z',
            ]]),
        ]);

        $channel = WhatsAppChannel::factory()->pairing()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
        $channel->storeInstanceApiKey('chave-instancia');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson('/api/v1/whatsapp/channel')
            ->assertOk()
            ->assertJsonPath('data.qr_code', '2@qr-corrente')
            ->assertJsonPath('data.qr_expires_at', '2026-10-08T15:00:00Z');
    }

    public function test_falha_de_transporte_do_qr_preserva_status_do_pareamento(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => ['status' => 'pairing']]),
            'wzap.test/instances/inst-1/qr' => Http::failedConnection(),
        ]);

        $channel = WhatsAppChannel::factory()->pairing()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
        $channel->storeInstanceApiKey('chave-instancia');
        $admin = $this->memberOf($this->account, 'admin');

        foreach (['/api/v1/whatsapp/channel', '/api/v1/whatsapp/channel/pairing'] as $route) {
            $this->actingAs($admin, 'web')->getJson($route)
                ->assertOk()
                ->assertJsonPath('data.status', 'pairing')
                ->assertJsonPath('data.qr_code', null);
        }

        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
        $this->assertNull($channel->last_error);
    }

    public function test_resposta_invalida_do_qr_preserva_status_do_pareamento(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => ['status' => 'pairing']]),
            'wzap.test/instances/inst-1/qr' => Http::response(['data' => 'resposta-invalida']),
        ]);

        $channel = WhatsAppChannel::factory()->pairing()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
        $channel->storeInstanceApiKey('chave-instancia');
        $admin = $this->memberOf($this->account, 'admin');

        foreach (['/api/v1/whatsapp/channel', '/api/v1/whatsapp/channel/pairing'] as $route) {
            $this->actingAs($admin, 'web')->getJson($route)
                ->assertOk()
                ->assertJsonPath('data.status', 'pairing')
                ->assertJsonPath('data.qr_code', null);
        }
    }

    public function test_ultimo_erro_do_canal_nao_expoe_chaves_ecoada_pelo_gateway(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => [
                'status' => 'error',
                'last_error' => 'autenticação recusada para chave-instancia e chave-global',
            ]]),
        ]);

        $channel = WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson('/api/v1/whatsapp/channel')
            ->assertOk()
            ->assertJsonPath('data.last_error', 'autenticação recusada para [redigido] e [redigido]');

        $this->assertSame(WhatsAppChannel::STATUS_ERROR, $channel->refresh()->status);
    }

    public function test_falha_do_pareamento_preserva_incerteza_sem_expor_chaves_ou_reenviar(): void
    {
        config()->set('whatsapp.wzap.webhook_url', null);
        $channel = WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
        Http::fake([
            'wzap.test/instances/inst-1/connect' => Http::response(['error' => [
                'code' => 'unauthorized',
                'message' => 'credenciais recusadas: chave-instancia e chave-global',
            ]], 401),
        ]);
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web');
        $response = $this->postJson('/api/v1/whatsapp/channel/pairing')->assertStatus(409)
            ->assertJsonPath('code', 'operation_reconciliation_required')
            ->assertJsonPath('next_step', 'whatsapp.connect');
        $this->assertStringNotContainsString('chave-instancia', $response->getContent());
        $this->assertStringNotContainsString('chave-global', $response->getContent());
        $this->assertNull($channel->refresh()->last_error);
        $operation = $response->json('operation_id');
        $this->postJson("/api/v1/whatsapp/channel/operations/{$operation}/continue")->assertStatus(409)
            ->assertJsonPath('code', 'operation_reconciliation_required');
        Http::assertSentCount(1);
    }

    public function test_flag_desligada_desliga_as_rotas_de_canal_com_404(): void
    {
        // Sem o wzap configurado a feature é inerte: o flag desliga as
        // quatro rotas do Canal com o mesmo 404 do Disparo — e nenhuma
        // requisição pode sair para o gateway.
        config()->set('whatsapp.enabled', false);

        WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
            'status' => WhatsAppChannel::STATUS_PAIRING,
        ]);

        $admin = $this->memberOf($this->account, 'admin');

        $this->actingAs($admin, 'web')->getJson('/api/v1/whatsapp/channel')->assertNotFound();
        $this->actingAs($admin, 'web')->postJson('/api/v1/whatsapp/channel/pairing')->assertNotFound();
        $this->actingAs($admin, 'web')->getJson('/api/v1/whatsapp/channel/pairing')->assertNotFound();
        $this->actingAs($admin, 'web')->deleteJson('/api/v1/whatsapp/channel')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_polling_do_pareamento_atualiza_o_canal_quando_o_wzap_conecta(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => [
                'status' => 'connected',
                'whatsapp_jid' => '5511999999999@s.whatsapp.net',
                'last_connected_at' => '2026-10-04T10:00:00Z',
            ]]),
        ]);

        $channel = WhatsAppChannel::factory()->pairing()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
        $channel->storeInstanceApiKey('chave-instancia');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk()
            ->assertJsonPath('data.status', 'connected');

        $channel->refresh();
        $this->assertSame(WhatsAppChannel::STATUS_CONNECTED, $channel->status);
        $this->assertNotNull($channel->connected_at);
    }

    public function test_polling_em_pareamento_devolve_o_qr_corrente(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => [
                'status' => 'pairing',
                'whatsapp_jid' => '',
                'last_error' => '',
            ]]),
            'wzap.test/instances/inst-1/qr' => Http::response(['data' => [
                'status' => 'pairing',
                'qr_code' => '2@qr-ainda-valido',
                'qr_expires_at' => '2026-10-05T13:00:00Z',
            ]]),
        ]);

        $channel = WhatsAppChannel::factory()->pairing()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
        $channel->storeInstanceApiKey('chave-instancia');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk()
            ->assertJsonPath('data.status', 'pairing')
            ->assertJsonPath('data.qr_code', '2@qr-ainda-valido')
            ->assertJsonPath('data.last_error', null);
    }

    public function test_polling_sem_pareamento_em_curso_responde_404(): void
    {
        WhatsAppChannel::factory()->create([
            'account_id' => $this->account->getKey(),
            'status' => WhatsAppChannel::STATUS_DISCONNECTED,
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson('/api/v1/whatsapp/channel/pairing')
            ->assertNotFound();
    }

    public function test_admin_desconecta_e_canal_volta_para_disconnected(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/disconnect' => Http::response(null, 204),
        ]);

        $channel = WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->deleteJson('/api/v1/whatsapp/channel')
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected');

        $channel->refresh();
        $this->assertSame(WhatsAppChannel::STATUS_DISCONNECTED, $channel->status);
        $this->assertNull($channel->connected_at);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'http://wzap.test/instances/inst-1/disconnect'
            && ($request->header('apikey')[0] ?? null) === 'chave-instancia');
    }

    public function test_resposta_nao_carrega_apikey_nem_cifra(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'id' => 'inst-1',
                'external_ref' => (string) $this->account->getKey(),
                'status' => 'disconnected',
                'instance_api_key' => 'chave-secreta-do-wzap',
            ]], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => [
                'status' => 'pairing',
                'qr_code' => '2@qr-do-teste',
                'qr_expires_at' => '2026-10-04T12:00:00Z',
            ]]),
        ]);

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk();

        $this->assertStringNotContainsString('chave-secreta-do-wzap', $response->getContent());
        $this->assertStringNotContainsString('instance_api_key', $response->getContent());
    }

    public function test_erro_do_wzap_no_pareamento_preserva_etapa_incerta_sem_persistir_erro_na_projecao(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'id' => 'inst-1',
                'external_ref' => (string) $this->account->getKey(),
                'status' => 'disconnected',
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-1/connect' => Http::response([
                'error' => ['code' => 'internal', 'message' => 'session store offline'],
            ], 500),
        ]);

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertStatus(409)
            ->assertJsonPath('code', 'operation_reconciliation_required')
            ->assertJsonPath('next_step', 'whatsapp.connect');

        $this->assertStringNotContainsString('session store offline', $response->getContent());
        $this->assertStringNotContainsString('chave-instancia', $response->getContent());
        $this->assertNull(WhatsAppChannel::forAccount($this->account->getKey()));
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')->getJson('/api/v1/whatsapp/channel')
            ->assertOk()->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.operation.code', 'operation_reconciliation_required');
        Http::assertSentCount(2);
    }

    public function test_falha_antes_da_instancia_nao_persiste_linha_fantasma(): void
    {
        // Sem recibo válido da criação, nenhuma projeção é gravada. A leitura
        // publica apenas a operação incerta, sem consultar o provedor.
        Http::fake([
            'wzap.test/instances' => Http::response([
                'error' => ['code' => 'unavailable', 'message' => 'upstream indisponivel'],
            ], 503),
        ]);

        $admin = $this->memberOf($this->account, 'admin');

        $this->actingAs($admin, 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertStatus(409)
            ->assertJsonPath('code', 'operation_manual_review_required')
            ->assertJsonPath('next_step', 'whatsapp.instance.create');

        $this->assertSame(0, WhatsAppChannel::count());
        $this->assertNull(WhatsAppChannel::forAccount($this->account->getKey()));

        $this->actingAs($admin, 'web')->getJson('/api/v1/whatsapp/channel')->assertOk()
            ->assertJsonPath('data.status', 'disconnected')
            ->assertJsonPath('data.operation.code', 'operation_manual_review_required');
        $this->assertSame(0, WhatsAppChannel::count());
        Http::assertSentCount(1);
    }

    public function test_falha_de_rotacao_em_canal_existente_exige_revisao_sem_restaurar_chave_antiga(): void
    {
        $channel = WhatsAppChannel::factory()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
            'status' => WhatsAppChannel::STATUS_DISCONNECTED,
        ]);
        Http::fake([
            'wzap.test/instances/inst-1/apikey/rotate' => Http::response([
                'error' => ['code' => 'internal', 'message' => 'fila cheia'],
            ], 500),
        ]);
        $admin = $this->memberOf($this->account, 'admin');
        $this->actingAs($admin, 'web')->postJson('/api/v1/whatsapp/channel/pairing')->assertStatus(409)
            ->assertJsonPath('code', 'operation_manual_review_required')
            ->assertJsonPath('next_step', 'whatsapp.instance.rotate');
        $this->assertSame(WhatsAppChannel::STATUS_DISCONNECTED, $channel->refresh()->status);
        $this->assertNull($channel->last_error);
        $this->actingAs($admin, 'web')->getJson('/api/v1/whatsapp/channel')->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.last_error', null)
            ->assertJsonPath('data.operation.code', 'operation_manual_review_required');
        $this->assertNull($channel->refresh()->instanceApiKey());
        Http::assertSentCount(1);
    }

    public function test_last_error_gravado_limpa_quando_o_gateway_conecta(): void
    {
        WhatsAppChannel::factory()->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
            'status' => WhatsAppChannel::STATUS_ERROR,
            'last_error' => 'wzap internal: fila cheia',
        ]);

        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response(['data' => [
                'status' => 'connected',
                'whatsapp_jid' => '5511999999999@s.whatsapp.net',
            ]]),
        ]);

        $channel = WhatsAppChannel::forAccount($this->account->getKey());
        $channel->storeInstanceApiKey('chave-instancia');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson('/api/v1/whatsapp/channel')
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.last_error', null);

        $this->assertNull(WhatsAppChannel::forAccount($this->account->getKey())->last_error);
    }

    public function test_pareamento_com_resposta_fora_do_mapa_permanece_incerto_sem_confirmar(): void
    {
        // `/connect` com `status` desconhecido não confirma aplicação e não
        // autoriza reenvio: somente reconciliação explícita pode resolver.
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'id' => 'inst-1',
                'external_ref' => (string) $this->account->getKey(),
                'status' => 'disconnected',
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => [
                'status' => 'banned',
            ]]),
        ]);

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')->assertStatus(409)
            ->assertJsonPath('code', 'operation_reconciliation_required');
        $this->assertNull(WhatsAppChannel::forAccount($this->account->getKey()));
        $operation = $response->json('operation_id');
        $this->postJson("/api/v1/whatsapp/channel/operations/{$operation}/continue")->assertStatus(409)
            ->assertJsonPath('code', 'operation_reconciliation_required');
        Http::assertSentCount(2);
    }

    public function test_dois_inserts_concorrentes_viram_resposta_controlada(): void
    {
        // Emulação da corrida: um `creating` de outro "processo" grava a
        // linha vencedora entre o `forAccount` (que viu nada) e o `save()`
        // do canal novo. O INSERT perdedor não pode devolver 500 pela
        // unique de `account_id` — o pedido responde com o espelho atual.
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'id' => 'inst-1',
                'external_ref' => (string) $this->account->getKey(),
                'status' => 'disconnected',
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-1/connect' => Http::response(['data' => [
                'status' => 'pairing',
                'qr_code' => '2@qr-do-teste',
                'qr_expires_at' => '2026-10-04T12:00:00Z',
            ]]),
        ]);

        $winner = null;
        $accountId = $this->account->getKey();
        WhatsAppChannel::creating(function (WhatsAppChannel $model) use (&$winner, $accountId): void {
            if ($winner === null) {
                $winner = WhatsAppChannel::withoutEvents(fn () => WhatsAppChannel::factory()->create([
                    'account_id' => $accountId,
                    'wzap_instance_id' => 'inst-1',
                    'status' => WhatsAppChannel::STATUS_CONNECTED,
                ]));
            }
        });

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk()
            ->assertJsonPath('data.status', 'pairing');

        // O espelho venceu a corrida: status novo aplicado na linha que já
        // existia, nunca um INSERT duplicado.
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $winner->refresh()->status);
        $this->assertSame(1, WhatsAppChannel::where('account_id', $this->account->getKey())->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function test_read_only_polling_returns_live_status_without_changing_persisted_channel(): void
    {
        $channel = WhatsAppChannel::factory()->pairing()->create(['account_id' => $this->account->id, 'wzap_instance_id' => 'inst-1']);
        $channel->storeInstanceApiKey('chave-instancia');
        Http::fake(['wzap.test/instances/inst-1/status' => Http::response(['data' => ['status' => 'connected', 'last_connected_at' => '2026-10-04T10:00:00Z']])]);
        $this->actingAs($this->memberOf($this->account, 'user'))->getJson('/api/v1/whatsapp/channel/pairing')
            ->assertOk()->assertJsonPath('data.status', 'connected');
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
        $this->assertNull($channel->connected_at);
    }

    public function test_read_only_gateway_error_is_reported_without_writing_the_channel(): void
    {
        $channel = WhatsAppChannel::factory()->pairing()->create(['account_id' => $this->account->id, 'wzap_instance_id' => 'inst-1']);
        $channel->storeInstanceApiKey('chave-instancia');
        Http::fake(['*' => Http::failedConnection('private-key')]);
        $this->actingAs($this->memberOf($this->account, 'user'))->getJson('/api/v1/whatsapp/channel/pairing')->assertStatus(502);
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->refresh()->status);
        $this->assertNull($channel->last_error);
    }

    private function memberOf(Account $account, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
