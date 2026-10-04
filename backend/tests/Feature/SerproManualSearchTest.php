<?php

namespace Tests\Feature;

use App\Jobs\RunSerproManualSearchJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproManualSearch;
use App\Models\SerproMonitoring;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A busca manual sob demanda: um pedido por cliente, a cota de 10/mês por
 * `conta × cliente × obrigação` e a linha do painel nascendo junto. O que a
 * cota não é: por conta — um documento com problema não consome a régua dos
 * outros, e o mês calendário é a janela inteira.
 */
class SerproManualSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_operador_pede_busca_e_o_pedido_nasce_queued_com_job_despachado(): void
    {
        Queue::fake();
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => [$client->getKey()],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.requested', 1);

        $busca = SerproManualSearch::withoutGlobalScope('account')->sole();
        $this->assertSame($account->getKey(), $busca->account_id);
        $this->assertSame($client->getKey(), $busca->client_id);
        $this->assertSame('declaracoes/pgdas', $busca->obligation);
        $this->assertSame('queued', $busca->state->value);
        $this->assertSame('full', $busca->mode->value);
        $this->assertNull($busca->recalculate_date);
        $this->assertNotNull($busca->requested_by);

        Queue::assertPushed(RunSerproManualSearchJob::class, fn (RunSerproManualSearchJob $job): bool => $job->searchId === $busca->getKey() && $job->accountId === $account->getKey());
    }

    public function test_a_busca_em_massa_cria_um_pedido_e_uma_linha_de_painel_por_cliente(): void
    {
        Queue::fake();
        $account = Account::factory()->create();
        $clients = Client::factory()->company()->count(3)->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => $clients->pluck('id')->all(),
                'mode' => 'slip_status',
                'recalculate_date' => '2026-10-15',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.requested', 3);

        $this->assertSame(3, SerproManualSearch::count());
        $this->assertSame(3, SerproMonitoring::query()->where('obligation', 'declaracoes/pgdas')->count());
        Queue::assertPushed(RunSerproManualSearchJob::class, 3);

        // O que o modal pediu viaja no registro — o que o provedor faz com
        // ele é decisão do job, e não do POST.
        $busca = SerproManualSearch::query()->first();
        $this->assertSame('slip_status', $busca->mode->value);
        $this->assertSame('2026-10-15', $busca->recalculate_date->toDateString());
    }

    public function test_user_recebe_403(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => [$client->getKey()],
            ])
            ->assertForbidden();

        $this->assertSame(0, SerproManualSearch::count());
    }

    public function test_o_11º_pedido_do_par_estoura_a_cota_com_422_e_o_detalhe_do_cliente(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproManualSearch::factory()->count(10)->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        $response = $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => [$client->getKey()],
            ])
            ->assertUnprocessable();

        // O detalhe é do par que bloqueou: a tela precisa apontar o cliente,
        // e não um "cota esgotada" que valeria para a conta inteira.
        $this->assertStringContainsString((string) $client->getKey(), (string) $response->json('message').json_encode($response->json('errors')));
        $this->assertSame(10, SerproManualSearch::count());
    }

    public function test_a_cota_e_por_documento_e_por_cliente(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $outro = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproManualSearch::factory()->count(10)->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        // Outro documento do mesmo cliente tem régua própria...
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/caixas-postais/e-cac/clients/search', [
                'client_ids' => [$client->getKey()],
            ])
            ->assertStatus(202);

        // ...e o mesmo documento para outro cliente também.
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => [$outro->getKey()],
            ])
            ->assertStatus(202);
    }

    public function test_a_virada_do_mes_devolve_a_regua(): void
    {
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproManualSearch::factory()->count(10)->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => [$client->getKey()],
            ])
            ->assertUnprocessable();

        CarbonImmutable::setTestNow('2026-11-02 12:00:00');

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => [$client->getKey()],
            ])
            ->assertStatus(202);

        $this->assertSame(11, SerproManualSearch::count());
    }

    public function test_o_estouro_em_um_cliente_nao_impede_o_outro(): void
    {
        $account = Account::factory()->create();
        $estourado = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $ok = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproManualSearch::factory()->count(10)->create([
            'account_id' => $account->getKey(),
            'client_id' => $estourado->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        // O lote com um par estourado sai inteiro de fora — a validação é do
        // lote, e um meio-aplicado sob 422 mentiria sobre a cota.
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients/search', [
                'client_ids' => [$estourado->getKey(), $ok->getKey()],
            ])
            ->assertUnprocessable();

        $this->assertSame(10, SerproManualSearch::count());
    }

    public function test_o_endpoint_de_cota_devolve_used_e_limit_por_cliente_do_mes(): void
    {
        $account = Account::factory()->create();
        $comBusca = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $semBusca = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproManualSearch::factory()->count(3)->create([
            'account_id' => $account->getKey(),
            'client_id' => $comBusca->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        // Busca do mês passado não conta: a régua do mês corrente é a que a
        // barra do modal promete.
        SerproManualSearch::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $comBusca->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'created_at' => now()->subMonth(),
        ]);

        $response = $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/search-quota')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $linha = collect($response->json('data'))->firstWhere('client_id', $comBusca->getKey());
        $this->assertSame(3, $linha['used']);
        $this->assertSame(10, $linha['limit']);

        $outra = collect($response->json('data'))->firstWhere('client_id', $semBusca->getKey());
        $this->assertSame(0, $outra['used']);
        $this->assertSame(10, $outra['limit']);
    }

    public function test_slug_inexistente_e_404_e_sem_leitura_servida_e_422(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $member = $this->memberOf($account, 'admin');

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/inexistente/clients/search', ['client_ids' => [$client->getKey()]])
            ->assertNotFound();

        // `unavailable` e `extinct` não têm leitura: uma busca ali seria fila
        // nascida para falhar.
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/parcelamentos/pgfn/clients/search', ['client_ids' => [$client->getKey()]])
            ->assertUnprocessable();

        // O GET de cota segue o mesmo catálogo.
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/inexistente/search-quota')
            ->assertNotFound();

        $this->assertSame(0, SerproManualSearch::count());
    }

    public function test_a_busca_queued_aparece_como_processando_no_painel(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        (new SerproMonitoring)->forceFill([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'state' => 'sem_dados',
            'source_at' => null,
        ])->save();
        SerproManualSearch::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        // Mesma regra do item de run ativa: trabalho que o operador pediu e
        // a fila não respondeu não pode sumir do painel nem fingir resposta.
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.processando', 1)
            ->assertJsonPath('data_rows.0.situacao', 'processando')
            ->assertJsonPath('data_rows.0.client_id', $client->getKey());
    }

    public function test_a_busca_terminada_deixa_de_ser_processando(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        (new SerproMonitoring)->forceFill([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'state' => 'sem_dados',
            'source_at' => null,
        ])->save();
        SerproManualSearch::factory()->completed()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        // Terminal, sem resposta gravada na linha: saiu da listagem — a
        // busca falhou sem dado, e um `processando` eterno seria mentira.
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data_rows');
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
