<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppDocumentJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\BusinessTestCase;

/**
 * A API do Enviar guias: um POST por cliente, um Disparo `guia` por
 * Contato de envio WhatsApp marcado. A guia alvo é o período mais
 * recente com guia disponível (`slip_number` ou declaração transmitida),
 * e o reenvio da mesma guia ao mesmo contato é bloqueado — exceto por
 * recálculo (`slip_number` novo) ou Ciência de reenvio no request.
 * Contato `email` e contato já servido sem ciência voltam em `skipped`.
 */
class WhatsAppSendGuidesApiTest extends BusinessTestCase
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

    public function test_admin_envia_guia_para_os_contatos_whatsapp_marcados(): void
    {
        Queue::fake();
        $this->canalConectado();

        $socio = $this->contato('Sócio 1', 'whatsapp', '5511987654321');
        $contador = $this->contato('Contador', 'whatsapp', '5511912345678');
        $email = $this->contato('E-mail do financeiro', 'email', 'fin@cliente.test');

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), [
                'contact_ids' => [$socio->getKey(), $contador->getKey(), $email->getKey()],
            ])
            ->assertAccepted()
            ->assertJsonCount(2, 'data.dispatches')
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.dispatches.0.status', WhatsAppDispatch::STATUS_QUEUED)
            ->assertJsonPath('data.dispatches.0.artifact_type', 'guia')
            // A guia alvo é o período mais recente com guia disponível.
            ->assertJsonPath('data.dispatches.0.competence', '2026-02')
            ->assertJsonPath('data.dispatches.0.slip_number', '07202215764029999')
            ->assertJsonPath('data.dispatches.0.contact.id', $socio->getKey())
            ->assertJsonPath('data.dispatches.0.contact.value', '5511987654321')
            ->assertJsonPath('data.skipped.0.contact_id', $email->getKey());

        $dispatches = WhatsAppDispatch::query()->orderBy('id')->get();
        $this->assertCount(2, $dispatches);
        $this->assertSame($socio->getKey(), $dispatches[0]->client_dispatch_contact_id);
        $this->assertSame('07202215764029999', $dispatches[0]->slip_number);
        $this->assertSame('2026-02', $dispatches[0]->competence);
        $this->assertSame($contador->getKey(), $dispatches[1]->client_dispatch_contact_id);

        Queue::assertPushed(SendWhatsAppDocumentJob::class, 2);
    }

    public function test_operador_dispara_e_user_recebe_403(): void
    {
        Queue::fake();
        $this->canalConectado();
        $socio = $this->contato(null, 'whatsapp', '5511987654321');

        $this->actingAs($this->memberOf($this->account, 'operador'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertAccepted();

        $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertForbidden();

        $this->assertSame(1, WhatsAppDispatch::count());
    }

    public function test_mesma_guia_ao_mesmo_contato_e_ignorada_sem_ciencia_de_reenvio(): void
    {
        Queue::fake();
        $this->canalConectado();
        $socio = $this->contato(null, 'whatsapp', '5511987654321');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertAccepted();

        // Segunda confirmação da mesma guia lógica: skipped, nada nasce.
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertAccepted()
            ->assertJsonCount(0, 'data.dispatches')
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.skipped.0.contact_id', $socio->getKey());

        $this->assertSame(1, WhatsAppDispatch::count());
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 1);
    }

    public function test_ciencia_de_reenvio_libera_novo_disparo_da_mesma_guia(): void
    {
        Queue::fake();
        $this->canalConectado();
        $socio = $this->contato(null, 'whatsapp', '5511987654321');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertAccepted();

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), [
                'contact_ids' => [$socio->getKey()],
                'resend_acknowledgements' => [$socio->getKey()],
            ])
            ->assertAccepted()
            ->assertJsonCount(1, 'data.dispatches');

        $this->assertSame(2, WhatsAppDispatch::count());
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 2);
    }

    public function test_recalculo_com_slip_number_novo_libera_o_envio_sem_ciencia(): void
    {
        Queue::fake();
        $this->canalConectado();
        $socio = $this->contato(null, 'whatsapp', '5511987654321');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertAccepted();

        // O sync trouxe uma guia recalculada para a mesma competência.
        $monitoring = SerproMonitoring::query()->where('client_id', $this->client->getKey())->firstOrFail();
        $periods = collect($monitoring->periods)->map(function (array $period): array {
            if (($period['period'] ?? null) === '2026-02') {
                $period['slip_number'] = '07202215764028888';
            }

            return $period;
        })->all();
        $monitoring->forceFill(['periods' => $periods])->save();

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertAccepted()
            ->assertJsonCount(1, 'data.dispatches')
            ->assertJsonPath('data.dispatches.0.slip_number', '07202215764028888');

        $this->assertSame(2, WhatsAppDispatch::count());
    }

    public function test_disparo_falho_da_mesma_guia_nao_bloqueia_nova_tentativa(): void
    {
        Queue::fake();
        $channel = $this->canalConectado();
        $socio = $this->contato(null, 'whatsapp', '5511987654321');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web');
        $payload = ['contact_ids' => [$socio->getKey()]];
        $primeiro = $this->postJson($this->sendGuidesUrl(), $payload)->assertAccepted();
        $falho = WhatsAppDispatch::findOrFail($primeiro->json('data.dispatches.0.id'));
        $channel->forceFill(['status' => WhatsAppChannel::STATUS_DISCONNECTED])->save();
        (new SendWhatsAppDocumentJob($this->account->getKey(), $falho->getKey()))->handle();
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $falho->fresh()->status);
        $channel->forceFill(['status' => WhatsAppChannel::STATUS_CONNECTED])->save();

        $segundo = $this->postJson($this->sendGuidesUrl(), $payload)
            ->assertAccepted()
            ->assertJsonCount(1, 'data.dispatches')
            ->assertJsonPath('data.dispatches.0.status', WhatsAppDispatch::STATUS_QUEUED);

        $this->assertNotSame($falho->getKey(), $segundo->json('data.dispatches.0.id'));
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $falho->fresh()->status);
        $this->assertSame('O Canal WhatsApp do escritório não está conectado.', $falho->fresh()->failure_reason);
        $this->assertSame(2, WhatsAppDispatch::count());
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 2);

        $this->postJson($this->sendGuidesUrl(), $payload)->assertAccepted()
            ->assertJsonCount(0, 'data.dispatches')
            ->assertJsonCount(1, 'data.skipped');
        $this->assertSame(2, WhatsAppDispatch::count());
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 2);
    }

    public function test_sem_contato_whatsapp_marcado_responde_422(): void
    {
        Queue::fake();
        $this->canalConectado();
        $email = $this->contato(null, 'email', 'fin@cliente.test');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$email->getKey()]])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_sem_nenhuma_guia_disponivel_responde_422(): void
    {
        Queue::fake();
        $this->canalConectado();
        $this->contato(null, 'whatsapp', '5511987654321');

        $this->monitoring($this->client, [[
            'period' => '2026-03',
            'declared_at' => null,
            'declaration_number' => null,
            'rectified' => false,
            'slip_number' => null,
            'slip_issued_at' => null,
            'due_on' => null,
            'slip_paid' => null,
        ]]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), [
                'contact_ids' => [ClientDispatchContact::query()->firstOrFail()->getKey()],
            ])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
    }

    public function test_canal_desconectado_responde_422_com_o_estado(): void
    {
        Queue::fake();
        WhatsAppChannel::factory()->create([
            'account_id' => $this->account->getKey(),
            'status' => WhatsAppChannel::STATUS_DISCONNECTED,
        ]);
        $socio = $this->contato(null, 'whatsapp', '5511987654321');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertStatus(422)
            ->assertJsonPath('data.channel_status', WhatsAppChannel::STATUS_DISCONNECTED);

        $this->assertSame(0, WhatsAppDispatch::count());
    }

    public function test_contato_de_outro_cliente_responde_422(): void
    {
        Queue::fake();
        $this->canalConectado();
        $outroCliente = Client::factory()->company()->create(['account_id' => $this->account->getKey()]);
        $contatoAlheio = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $outroCliente->getKey(),
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$contatoAlheio->getKey()]])
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppDispatch::count());
    }

    public function test_contato_de_outra_conta_responde_422_sem_vazar_existencia(): void
    {
        Queue::fake();
        $this->canalConectado();
        $outraConta = Account::factory()->create();
        $clienteAlheio = Client::factory()->company()->create(['account_id' => $outraConta->getKey()]);
        $contatoAlheio = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $outraConta->getKey(),
            'client_id' => $clienteAlheio->getKey(),
        ]);

        $response = $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$contatoAlheio->getKey()]])
            ->assertStatus(422);

        // A mensagem é neutra: não distingue "não existe" de "é de outra
        // conta" — o erro não vaza a existência do id alheio.
        $this->assertSame(
            'Contato de envio inválido para este cliente.',
            $response->json('errors.contact_ids.0'),
        );
        $this->assertSame(0, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_obrigacao_diferente_de_pgdas_responde_404(): void
    {
        $this->canalConectado();
        $socio = $this->contato(null, 'whatsapp', '5511987654321');
        $url = "/api/v1/serpro/monitoring/obligations/caixapostal/clients/{$this->client->getKey()}/whatsapp/send-guides";

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($url, ['contact_ids' => [$socio->getKey()]])
            ->assertNotFound();
    }

    public function test_flag_desligada_responde_404(): void
    {
        config()->set('whatsapp.enabled', false);
        $this->canalConectado();
        $socio = $this->contato(null, 'whatsapp', '5511987654321');

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->sendGuidesUrl(), ['contact_ids' => [$socio->getKey()]])
            ->assertNotFound();
    }

    public function test_suporte_dispara_em_conta_alheia_com_auditoria_por_disparo_requires_real_membership(): void
    {
        $targetAccount = Account::factory()->create();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $targetAccount->id);
        $this->actingAs($superAdmin)->postJson('/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/1/whatsapp/send-guides', [])
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_rastreio_sem_competencia_devolve_o_historico_do_cliente(): void
    {
        $this->canalConectado();
        $socio = $this->contato('Sócio 1', 'whatsapp', '5511987654321');

        WhatsAppDispatch::factory()->sent('wamid.antigo')->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'client_dispatch_contact_id' => $socio->getKey(),
            'competence' => '2026-01',
            'slip_number' => '07202215764027777',
        ]);
        WhatsAppDispatch::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'client_dispatch_contact_id' => $socio->getKey(),
            'competence' => '2026-02',
            'slip_number' => '07202215764029999',
        ]);

        $url = "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/dispatches";

        $response = $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Mais recente primeiro, com o destino que o cartão do painel mostra.
        $this->assertSame('2026-02', $response->json('data.0.competence'));
        $this->assertSame('07202215764029999', $response->json('data.0.slip_number'));
        $this->assertSame('5511987654321', $response->json('data.0.contact.value'));
        $this->assertSame('Sócio 1', $response->json('data.0.contact.label'));

        // O filtro por competência segue valendo quando informado.
        $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->getJson($url.'?competence=2026-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_id', 'wamid.antigo');
    }

    /**
     * Linha de monitoramento PGDAS: duas competências, a mais recente
     * (`2026-02`) com guia disponível — é ela que o Enviar guias mira.
     *
     * @param  list<array<string, mixed>>|null  $periods
     */
    private function monitoring(Client $client, ?array $periods = null): void
    {
        SerproMonitoring::query()->where('client_id', $client->getKey())->delete();

        SerproMonitoring::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'periods' => $periods ?? [
                [
                    'period' => '2026-02',
                    'declared_at' => '2026-02-28T10:00:00Z',
                    'declaration_number' => '00000000202602001',
                    'rectified' => false,
                    'slip_number' => '07202215764029999',
                    'slip_issued_at' => '2026-03-01T09:00:00Z',
                    'due_on' => null,
                    'slip_paid' => false,
                ],
                [
                    'period' => '2026-01',
                    'declared_at' => '2026-01-31T10:00:00Z',
                    'declaration_number' => '00000000202601001',
                    'rectified' => false,
                    'slip_number' => '07202215764027777',
                    'slip_issued_at' => '2026-02-01T09:00:00Z',
                    'due_on' => null,
                    'slip_paid' => true,
                ],
            ],
        ]);
    }

    private function contato(?string $label, string $channel, string $value): ClientDispatchContact
    {
        return ClientDispatchContact::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'label' => $label,
            'channel' => $channel,
            'value' => $value,
        ]);
    }

    private function canalConectado(): WhatsAppChannel
    {
        return WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);
    }

    private function sendGuidesUrl(): string
    {
        return "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/send-guides";
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
