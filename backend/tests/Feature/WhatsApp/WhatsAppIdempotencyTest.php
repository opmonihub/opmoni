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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\BusinessTestCase;

/**
 * Idempotência do Disparo: mesmo envio lógico (conta + cliente + contato
 * + obrigação + artefato + competência + slip_number) é um só envio ao
 * wzap. O segundo POST reencontra a linha e devolve o estado dela —
 * nunca um segundo `sendMedia`, em nenhum dia. Não há mais janela de
 * 24 h: a chave lógica é estável, e o que libera um novo envio da mesma
 * guia é o `slip_number` mudar (recálculo) — ou a Ciência de reenvio,
 * que o fluxo Enviar guias resolve com uma chave única por confirmação.
 */
class WhatsAppIdempotencyTest extends BusinessTestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;

        config()->set('whatsapp.enabled', true);

        $this->account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $this->account->getKey();
        $this->client = Client::factory()->company()->create([
            'account_id' => $this->account->getKey(),
            'phone' => '5511987654321',
        ]);

        WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);

        // O contato que o POST legado resolve: o WhatsApp mais antigo do
        // cliente — o mesmo que o backfill de `clients.phone` semeia.
        ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'value' => '5511987654321',
        ]);

        SerproMonitoring::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'periods' => [[
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

        $this->admin = $this->memberOf($this->account, 'admin');
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_dois_posts_iguais_enfileiram_um_so_envio(): void
    {
        Queue::fake();

        $primeiro = $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        $segundo = $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        $this->assertSame(1, WhatsAppDispatch::count());
        $this->assertSame(
            $primeiro->json('data.id'),
            $segundo->json('data.id'),
        );

        Queue::assertPushed(SendWhatsAppDocumentJob::class, 1);
    }

    public function test_post_identico_apos_falha_cria_tentativa_e_deduplica_os_proximos_cliques(): void
    {
        Queue::fake();
        $this->actingAs($this->admin, 'web');
        $payload = ['competence' => '2026-01', 'artifact' => 'guia'];
        $primeiro = $this->postJson($this->url(), $payload)->assertAccepted();
        $falho = WhatsAppDispatch::findOrFail($primeiro->json('data.id'));
        $channel = WhatsAppChannel::forAccount($this->account->getKey());
        $channel->forceFill(['status' => WhatsAppChannel::STATUS_DISCONNECTED])->save();
        (new SendWhatsAppDocumentJob($this->account->getKey(), $falho->getKey()))->handle();
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $falho->fresh()->status);
        $channel->forceFill(['status' => WhatsAppChannel::STATUS_CONNECTED])->save();

        $segundo = $this->postJson($this->url(), $payload)->assertAccepted()
            ->assertJsonPath('data.status', WhatsAppDispatch::STATUS_QUEUED);
        $this->assertNotSame($falho->getKey(), $segundo->json('data.id'));
        $this->postJson($this->url(), $payload)->assertAccepted()
            ->assertJsonPath('data.id', $segundo->json('data.id'));
        $this->assertSame(2, WhatsAppDispatch::count());
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $falho->fresh()->status);
        $this->assertSame('O Canal WhatsApp do escritório não está conectado.', $falho->fresh()->failure_reason);
        $this->assertNotSame($falho->idempotency_key, WhatsAppDispatch::findOrFail($segundo->json('data.id'))->idempotency_key);
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 2);

        $novaFalha = WhatsAppDispatch::findOrFail($segundo->json('data.id'));
        $novaFalha->forceFill(['status' => WhatsAppDispatch::STATUS_FAILED])->save();
        $terceiro = $this->postJson($this->url(), $payload)->assertAccepted()
            ->assertJsonPath('data.status', WhatsAppDispatch::STATUS_QUEUED);
        $this->postJson($this->url(), $payload)->assertAccepted()
            ->assertJsonPath('data.id', $terceiro->json('data.id'));
        $this->assertSame(3, WhatsAppDispatch::count());
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 3);
    }

    public function test_dia_seguinte_nao_permite_novo_disparo_da_mesma_guia(): void
    {
        Queue::fake();

        $this->travelTo(now()->startOfDay()->setHour(10));

        $primeiro = $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        $this->travelTo(now()->addDay());

        $segundo = $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        // A janela de 24 h deixou de ser a regra: a mesma guia lógica
        // reencontra a linha original em qualquer dia.
        $this->assertSame(1, WhatsAppDispatch::count());
        $this->assertSame(
            $primeiro->json('data.id'),
            $segundo->json('data.id'),
        );
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 1);
    }

    public function test_guia_recalculada_na_mesma_competencia_nao_colide(): void
    {
        Queue::fake();

        $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        // O sync atualizou o slip da competência: o legado reencontra a
        // guia nova e gera uma linha própria para ela.
        $monitoring = SerproMonitoring::query()->where('client_id', $this->client->getKey())->firstOrFail();
        $periods = collect($monitoring->periods)->map(function (array $period): array {
            if (($period['period'] ?? null) === '2026-01') {
                $period['slip_number'] = '07202215764028888';
            }

            return $period;
        })->all();
        $monitoring->forceFill(['periods' => $periods])->save();

        $segundo = $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        $this->assertSame(2, WhatsAppDispatch::count());
        $this->assertSame('07202215764028888', $segundo->json('data.slip_number'));
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 2);
    }

    public function test_artefato_diferente_na_mesma_competencia_nao_colide(): void
    {
        Queue::fake();

        $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted();

        $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertAccepted();

        $this->assertSame(2, WhatsAppDispatch::count());
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 2);
    }

    public function test_corrida_na_unique_devolve_a_linha_vencedora(): void
    {
        Queue::fake();

        // Emulação da corrida: entre o lookup da chave e o INSERT, "outro
        // processo" grava a linha. O POST não pode devolver 500 — a unique
        // é a rede de segurança, e a resposta é a linha que já existe.
        $winner = null;
        $account = $this->account;
        $client = $this->client;
        DB::listen(function (QueryExecuted $query) use (&$winner, $account, $client): void {
            if ($winner === null && str_starts_with($query->sql, 'select')
                && str_contains($query->sql, '"whatsapp_dispatches"')
                && str_contains($query->sql, '"idempotency_key" =')) {
                $winner = WhatsAppDispatch::factory()->create([
                    'account_id' => $account->getKey(),
                    'client_id' => $client->getKey(),
                    'artifact_type' => 'guia',
                    'competence' => '2026-01',
                    'idempotency_key' => $query->bindings[1],
                    'status' => WhatsAppDispatch::STATUS_SENT,
                    'message_id' => 'wamid.vencedor',
                ]);
            }
        });

        $response = $this->actingAs($this->admin, 'web')
            ->postJson($this->url(), ['competence' => '2026-01', 'artifact' => 'guia'])
            ->assertAccepted()
            ->assertJsonPath('data.id', $winner->getKey());

        $this->assertSame(1, WhatsAppDispatch::count());
        // A linha vencedora já estava `sent`: o perdedor reencontra o
        // resultado dela e nada é re-enfileirado por cima do que já saiu.
        $this->assertSame('wamid.vencedor', $response->json('data.message_id'));
    }

    private function url(): string
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
}
