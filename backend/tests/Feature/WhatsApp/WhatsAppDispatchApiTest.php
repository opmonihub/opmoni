<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppDocumentJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Models\SerproMonitoring;
use App\Models\SupportAccessLog;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\BusinessTestCase;

/**
 * A API do Disparo PGDAS: `admin`/`operador` enfileiram o envio do
 * documento da competência para o telefone do cliente; `user` lê o
 * Rastreio mas não dispara. O wzap é falso (`Http::fake`) e a fila é
 * dublê (`Queue::fake`) — o que se verifica aqui é o contrato do POST e
 * do GET, e a linha que nasce em `whatsapp_dispatches`.
 */
class WhatsAppDispatchApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;

        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.wzap.base_url', 'http://wzap.test');
        config()->set('whatsapp.wzap.api_key', 'chave-global');

        $this->account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $this->account->getKey();
        $this->client = Client::factory()->company()->create([
            'account_id' => $this->account->getKey(),
            'phone' => '5511987654321',
        ]);

        $this->monitoring($this->client);
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_admin_dispara_documento_e_recebe_202_com_disparo_enfileirado(): void
    {
        Queue::fake();
        $this->canalConectado();

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertAccepted()
            ->assertJsonPath('data.status', WhatsAppDispatch::STATUS_QUEUED)
            ->assertJsonPath('data.client_id', $this->client->getKey())
            ->assertJsonPath('data.artifact_type', 'recibo')
            ->assertJsonPath('data.competence', '2026-01')
            ->assertJsonPath('data.obligation', WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS);

        $dispatch = WhatsAppDispatch::query()->first();
        $this->assertNotNull($dispatch);
        $this->assertSame($this->account->getKey(), $dispatch->account_id);
        $this->assertSame(WhatsAppDispatch::idempotencyKey(
            $this->account->getKey(),
            $this->client->getKey(),
            WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS,
            'recibo',
            '2026-01',
            $dispatch->client_dispatch_contact_id,
            '07202215764027873',
        ), $dispatch->idempotency_key);
        $this->assertNotNull($dispatch->client_dispatch_contact_id);
        $this->assertSame('07202215764027873', $dispatch->slip_number);
        $this->assertFalse($dispatch->dispatched_in_support);

        // A resposta nunca carrega apikey nem segredo — o destino vem
        // no sub-objeto `contact`, que é o que o painel exibe.
        $this->assertSame('5511987654321', $response->json('data.contact.value'));
        $this->assertStringNotContainsString('chave-instancia', $response->getContent());

        Queue::assertPushed(SendWhatsAppDocumentJob::class, fn (SendWhatsAppDocumentJob $job): bool => $job->accountId === $this->account->getKey()
            && $job->dispatchId === $dispatch->getKey());
    }

    public function test_operador_dispara_e_enfileira(): void
    {
        Queue::fake();
        $this->canalConectado();

        $this->actingAs($this->memberOf($this->account, 'operador'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        Queue::assertPushed(SendWhatsAppDocumentJob::class, 1);
    }

    public function test_user_recebe_403_no_disparo_e_nada_nasce(): void
    {
        Queue::fake();
        $this->canalConectado();

        $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertForbidden();

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_canal_desconectado_responde_422_com_o_estado(): void
    {
        Queue::fake();
        WhatsAppChannel::factory()->create([
            'account_id' => $this->account->getKey(),
            'status' => WhatsAppChannel::STATUS_DISCONNECTED,
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertStatus(422)
            ->assertJsonPath('data.channel_status', WhatsAppChannel::STATUS_DISCONNECTED);

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_conta_sem_canal_responde_422(): void
    {
        Queue::fake();

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_cliente_sem_contato_whatsapp_responde_422_com_motivo_legivel(): void
    {
        Queue::fake();
        $this->canalConectado();
        $this->client->forceFill(['phone' => null])->save();
        ClientDispatchContact::query()->delete();

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
        $this->assertNotNull($response->json('message'));
    }

    public function test_contato_whatsapp_com_telefone_fora_do_padrao_responde_422(): void
    {
        Queue::fake();
        $this->canalConectado();
        ClientDispatchContact::query()
            ->where('client_id', $this->client->getKey())
            ->update(['value' => '123']);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_artefato_indisponivel_na_linha_responde_422(): void
    {
        Queue::fake();
        $this->canalConectado();
        // Linha sem `declaration_number`: recibo/declaração não existem,
        // e sem `slip_number` nem `declared_at` nem a guia sai.
        $this->monitoring($this->client, [[
            'period' => '2026-02',
            'declared_at' => null,
            'declaration_number' => null,
            'rectified' => false,
            'slip_number' => null,
            'slip_issued_at' => null,
            'due_on' => null,
            'slip_paid' => null,
        ]]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-02', 'artifact' => 'recibo'])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_competencia_nao_sincronizada_responde_422(): void
    {
        Queue::fake();
        $this->canalConectado();

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2030-12', 'artifact' => 'guia'])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_obrigacao_diferente_de_pgdas_responde_404(): void
    {
        $this->canalConectado();
        $url = "/api/v1/serpro/monitoring/obligations/caixapostal/clients/{$this->client->getKey()}/whatsapp/dispatch";

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($url, ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertNotFound();
    }

    public function test_flag_desligada_responde_404(): void
    {
        config()->set('whatsapp.enabled', false);
        $this->canalConectado();

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertNotFound();

        $this->assertSame(0, WhatsAppDispatch::count());
    }

    public function test_suporte_dispara_em_conta_alheia_com_flag_e_auditoria_requires_real_membership(): void
    {
        $targetAccount = Account::factory()->create();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $targetAccount->id);
        $this->actingAs($superAdmin)->postJson('/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/1/whatsapp/dispatch', [])
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_membro_dispara_sem_gerar_log_de_suporte(): void
    {
        Queue::fake();
        $this->canalConectado();

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->dispatchUrl(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        $dispatch = WhatsAppDispatch::query()->first();
        $this->assertFalse((bool) $dispatch->dispatched_in_support);
        $this->assertNull($dispatch->support_access_log_id);
        $this->assertSame(0, SupportAccessLog::count());
    }

    public function test_rastreio_da_linha_e_leitura_de_qualquer_membro(): void
    {
        $this->canalConectado();

        WhatsAppDispatch::factory()->sent('wamid.recibo-1')->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'artifact_type' => 'recibo',
            'competence' => '2026-01',
        ]);
        WhatsAppDispatch::factory()->failed('O gateway recusou o envio.')->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'artifact_type' => 'guia',
            'competence' => '2026-01',
        ]);
        // Linha de outra conta não pode vazar na listagem.
        $outraConta = Account::factory()->create();
        WhatsAppDispatch::factory()->sent()->create([
            'account_id' => $outraConta->getKey(),
            'competence' => '2026-01',
        ]);

        $url = "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/dispatches?competence=2026-01";

        $response = $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $statuses = collect($response->json('data'))->pluck('status')->sort()->values();
        $this->assertSame([WhatsAppDispatch::STATUS_FAILED, WhatsAppDispatch::STATUS_SENT], $statuses->all());

        // Filtro por artefato estreita a leitura da linha.
        $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->getJson($url.'&artifact=recibo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_id', 'wamid.recibo-1')
            ->assertJsonPath('data.0.artifact_type', 'recibo');
    }

    public function test_rastreio_sem_competencia_devolve_o_historico_do_cliente(): void
    {
        WhatsAppDispatch::factory()->sent()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'competence' => '2025-12',
        ]);
        WhatsAppDispatch::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'competence' => '2026-01',
        ]);

        $url = "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/dispatches";

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Sem competência o painel lê o histórico inteiro, do mais novo
        // para o mais antigo.
        $this->assertSame('2026-01', $response->json('data.0.competence'));
        $this->assertSame('2025-12', $response->json('data.1.competence'));
    }

    public function test_rastreio_de_cliente_de_outra_conta_responde_404(): void
    {
        $outraConta = Account::factory()->create();
        $clienteAlheio = Client::factory()->company()->create(['account_id' => $outraConta->getKey()]);

        $url = "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$clienteAlheio->getKey()}/whatsapp/dispatches?competence=2026-01";

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->getJson($url)
            ->assertNotFound();
    }

    /**
     * Linha de monitoramento PGDAS com os períodos dados — por padrão uma
     * competência `2026-01` completa, com guia e recibo disponíveis.
     *
     * @param  list<array<string, mixed>>|null  $periods
     */
    private function monitoring(Client $client, ?array $periods = null): void
    {
        // A linha é única por (conta, cliente, obrigação): quem refaz o
        // período troca o que existe, não cria uma segunda.
        SerproMonitoring::query()->where('client_id', $client->getKey())->delete();

        SerproMonitoring::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'periods' => $periods ?? [[
                'period' => '2026-01',
                'declared_at' => '2026-01-31T10:00:00Z',
                'declaration_number' => '00000000202601001',
                'rectified' => false,
                'slip_number' => '07202215764027873',
                'slip_issued_at' => '2026-02-01T09:00:00Z',
                'due_on' => null,
                'slip_paid' => true,
            ]],
        ]);
    }

    private function canalConectado(): WhatsAppChannel
    {
        $channel = WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);

        // O contato que o POST legado resolve: o WhatsApp mais antigo do
        // cliente — o mesmo que o backfill de `clients.phone` semeia.
        $existe = ClientDispatchContact::query()
            ->where('client_id', $this->client->getKey())
            ->where('channel', ClientDispatchContact::CHANNEL_WHATSAPP)
            ->where('value', '5511987654321')
            ->exists();

        if (! $existe) {
            ClientDispatchContact::factory()->whatsapp()->create([
                'account_id' => $this->account->getKey(),
                'client_id' => $this->client->getKey(),
                'value' => '5511987654321',
            ]);
        }

        return $channel;
    }

    private function dispatchUrl(): string
    {
        return "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/dispatch";
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }

    private function superAdminWithOwnAccount(): User
    {
        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $home = Account::factory()->create();

        AccountUser::create([
            'account_id' => $home->getKey(),
            'user_id' => $superAdmin->getKey(),
            'role' => 'admin',
        ]);

        $this->selectBusinessAccount($superAdmin, $home->getKey());

        return $superAdmin->refresh();
    }
}
