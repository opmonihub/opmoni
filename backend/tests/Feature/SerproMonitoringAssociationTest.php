<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproMonitoring;
use App\Models\SerproSyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A associação de cliente a obrigação: grava o vínculo e nada mais. O que ela
 * se recusa a fazer é o que a separa do disparo — nenhum job, nenhuma
 * execução, nenhuma chamada ao provedor: a cota é gasta pela execução, e um
 * POST que despachasse a integração seria o botão "Adicionar clientes"
 * cobrando do provedor sem pedir.
 */
class SerproMonitoringAssociationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operador_associa_e_a_repeticao_conta_como_ja_associado(): void
    {
        $account = Account::factory()->create();
        $novo = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $antigo = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $antigo->getKey(),
            'obligation' => 'declaracoes/pgdas',
        ]);

        $member = $this->memberOf($account, 'operador');
        $rota = '/api/serpro/monitoring/obligations/declaracoes/pgdas/clients';

        $this->actingAs($member, 'sanctum')
            ->postJson($rota, ['client_ids' => [$novo->getKey(), $antigo->getKey()]])
            ->assertOk()
            ->assertJsonPath('data.associated', 1)
            ->assertJsonPath('data.already', 1);

        // A segunda entrega da mesma lista é idempotente: a unique
        // `(account_id, client_id, obligation)` é a barreira, e o retorno diz
        // a verdade sobre o que mudou — nada.
        $this->actingAs($member, 'sanctum')
            ->postJson($rota, ['client_ids' => [$novo->getKey(), $antigo->getKey()]])
            ->assertOk()
            ->assertJsonPath('data.associated', 0)
            ->assertJsonPath('data.already', 2);
    }

    public function test_a_linha_associada_nasce_sem_dados_e_sem_fonte(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients', ['client_ids' => [$client->getKey()]])
            ->assertOk();

        $linha = SerproMonitoring::sole();
        $this->assertSame($account->getKey(), $linha->account_id);
        $this->assertSame($client->getKey(), $linha->client_id);
        $this->assertSame('declaracoes/pgdas', $linha->obligation);
        $this->assertSame('sem_dados', $linha->state);
        $this->assertNull($linha->source_at);
    }

    public function test_user_recebe_403(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients', ['client_ids' => [$client->getKey()]])
            ->assertForbidden();

        $this->assertSame(0, SerproMonitoring::count());
    }

    public function test_pf_e_cliente_de_outra_conta_respondem_422_e_nada_insere(): void
    {
        $account = Account::factory()->create();
        $pf = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $alheio = Client::factory()->company()->create();
        $pj = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $member = $this->memberOf($account, 'admin');
        $rota = '/api/serpro/monitoring/obligations/declaracoes/pgdas/clients';

        $this->actingAs($member, 'sanctum')
            ->postJson($rota, ['client_ids' => [$pf->getKey()]])
            ->assertUnprocessable();

        // A validação é do lote inteiro: um id estranho à conta no meio de
        // ids válidos rejeita o pedido — inserir os válidos antes de falhar
        // deixaria meio lote aplicado sob um `422`.
        $this->actingAs($member, 'sanctum')
            ->postJson($rota, ['client_ids' => [$pj->getKey(), $alheio->getKey()]])
            ->assertUnprocessable();

        $this->assertSame(0, SerproMonitoring::count());
    }

    public function test_obrigacao_sem_fonte_responde_422_e_slug_inexistente_404(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $member = $this->memberOf($account, 'admin');

        // `unavailable` e `extinct` não aceitam vínculo: uma linha ali
        // fingiria que o provedor um dia responderá por ela.
        foreach (['parcelamentos/pgfn', 'declaracoes/dirf'] as $slug) {
            $this->actingAs($member, 'sanctum')
                ->postJson("/api/serpro/monitoring/obligations/{$slug}/clients", ['client_ids' => [$client->getKey()]])
                ->assertUnprocessable();
        }

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/inexistente/clients', ['client_ids' => [$client->getKey()]])
            ->assertNotFound();

        $this->assertSame(0, SerproMonitoring::count());
    }

    public function test_associar_nao_despacha_job_nem_cria_execucao(): void
    {
        Queue::fake();
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients', ['client_ids' => [$client->getKey()]])
            ->assertOk();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('serpro_sync_runs', 0);
        $this->assertSame(0, SerproSyncRun::withoutGlobalScope('account')->count());
    }

    public function test_a_associada_nao_entra_nos_numeros_sem_resposta_do_provedor(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $member = $this->memberOf($account, 'admin');

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients', ['client_ids' => [$client->getKey()]])
            ->assertOk();

        // O vínculo é pedido, não dado: `source_at` vazio fica fora do
        // total, e associar não inventa situação `em_dia`.
        $this->actingAs($member, 'sanctum')
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
