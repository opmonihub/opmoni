<?php

namespace Tests\Feature;

use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Jobs\FanOutSerproRunJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproCall;
use App\Models\SerproConnection;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Models\User;
use App\Services\SerproAccountEnablement;
use App\Services\SerproRunFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O disparo da sincronização: uma execução por conta, `queued` na hora e o
 * trabalho fora da request. As recusas são a parte que importa — papel,
 * flag, credencial e a execução que já corre.
 */
class SerproSyncRunApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dispara_e_recebe_a_execucao_queued(): void
    {
        Queue::fake();
        $account = $this->contaPronta();

        $response = $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/sync-runs')
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'queued');

        $run = SerproSyncRun::sole();

        $this->assertSame($account->getKey(), $run->account_id);
        $this->assertSame(SerproSyncRunState::Queued, $run->state);
        $this->assertSame($response->json('data.id'), $run->getKey());
        Queue::assertPushed(FanOutSerproRunJob::class, fn (FanOutSerproRunJob $job): bool => $job->accountId === $account->getKey() && $job->runId === $run->getKey());
    }

    public function test_operador_dispara_e_user_recebe_403(): void
    {
        Queue::fake();
        $account = $this->contaPronta();

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->postJson('/api/serpro/sync-runs')
            ->assertStatus(202);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->postJson('/api/serpro/sync-runs')
            ->assertForbidden();

        $this->assertSame(1, SerproSyncRun::count());
    }

    public function test_sem_credencial_responde_422_e_nao_cria_execucao(): void
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/sync-runs')
            ->assertUnprocessable();

        $this->assertSame(0, SerproSyncRun::count());
    }

    public function test_escritorio_desabilitado_responde_422(): void
    {
        $account = Account::factory()->create();
        $this->conexao();

        // A flag desligada é contenção: a resposta é de validação, e nenhuma
        // execução nasce para o operador confundir com "em andamento".
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/sync-runs')
            ->assertUnprocessable();

        $this->assertSame(0, SerproSyncRun::count());
    }

    public function test_segundo_disparo_responde_409_com_a_execucao_em_andamento(): void
    {
        Queue::fake();
        $account = $this->contaPronta();
        $admin = $this->memberOf($account, 'admin');

        $this->actingAs($admin, 'sanctum')->postJson('/api/serpro/sync-runs')->assertStatus(202);
        $run = SerproSyncRun::sole();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/serpro/sync-runs')
            ->assertStatus(409)
            ->assertJsonPath('run_id', $run->getKey());

        $this->assertSame(1, SerproSyncRun::count());
    }

    public function test_outra_conta_dispara_em_paralelo(): void
    {
        Queue::fake();
        $account = $this->contaPronta();
        $outra = $this->contaPronta();

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson('/api/serpro/sync-runs')->assertStatus(202);
        $this->actingAs($this->memberOf($outra, 'admin'), 'sanctum')
            ->postJson('/api/serpro/sync-runs')->assertStatus(202);

        // A guarda "uma por conta" é por conta: a execução alheia não trava a
        // daqui. O `withoutGlobalScope` porque o singleton do tenant ficou
        // com a segunda conta após o último POST.
        $this->assertSame(2, SerproSyncRun::withoutGlobalScope('account')->count());
        Queue::assertPushed(FanOutSerproRunJob::class, 2);
    }

    public function test_a_listagem_so_traz_as_execucoes_da_conta(): void
    {
        $account = $this->contaPronta();
        $outra = Account::factory()->create();

        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);
        SerproSyncRun::factory()->create(['account_id' => $outra->getKey()]);

        $response = $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/sync-runs')
            ->assertOk();

        $this->assertSame([$run->getKey()], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_execucao_de_outra_conta_responde_404(): void
    {
        $account = $this->contaPronta();
        $outra = Account::factory()->create();
        $runAlheio = SerproSyncRun::factory()->create(['account_id' => $outra->getKey()]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson("/api/serpro/sync-runs/{$runAlheio->getKey()}")
            ->assertNotFound();
    }

    public function test_o_detalhe_traz_os_itens_e_os_seis_contadores(): void
    {
        $account = $this->contaPronta();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'state' => SerproSyncItemState::Synchronized,
        ]);

        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());

        $response = $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->getJson("/api/serpro/sync-runs/{$run->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.synchronized', 1)
            ->assertJsonPath('data.indeterminate', 0)
            ->assertJsonPath('data.not_processed', 0);

        $this->assertSame($client->getKey(), $response->json('data.items.0.client_id'));
        $this->assertSame('sincronizado', $response->json('data.items.0.state'));
    }

    public function test_as_chamadas_da_execucao_expoem_metadados_e_nunca_payload(): void
    {
        $account = $this->contaPronta();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        SerproCall::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'id_sistema' => 'SITFIS',
            'id_servico' => 'RELATORIOSITFIS92',
            'request_tag' => str_repeat('b', 32),
        ]);

        $response = $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson("/api/serpro/sync-runs/{$run->getKey()}/calls")
            ->assertOk();

        $call = $response->json('data.0');
        $this->assertSame($client->getKey(), $call['client_id']);
        $this->assertSame('RELATORIOSITFIS92', $call['id_servico']);
        $this->assertSame(str_repeat('b', 32), $call['request_tag']);
        $this->assertArrayHasKey('billable', $call);
        $this->assertArrayNotHasKey('dados', $call);
    }

    public function test_as_chamadas_de_execucao_alheia_respondem_404(): void
    {
        $account = $this->contaPronta();
        $outra = Account::factory()->create();
        $runAlheio = SerproSyncRun::factory()->create(['account_id' => $outra->getKey()]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson("/api/serpro/sync-runs/{$runAlheio->getKey()}/calls")
            ->assertNotFound();
    }

    public function test_resync_de_execucao_terminada_cria_execucao_nova(): void
    {
        Queue::fake();
        $account = $this->contaPronta();
        $run = SerproSyncRun::factory()->create([
            'account_id' => $account->getKey(),
            'state' => SerproSyncRunState::Completed,
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->postJson("/api/serpro/sync-runs/{$run->getKey()}/resync")
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'queued');

        // O re-sync é execução nova com histórico próprio: reusar o id
        // apagaria a linha do tempo da execução anterior.
        $this->assertNotSame($run->getKey(), $response->json('data.id'));
        $this->assertSame(2, SerproSyncRun::count());
        $this->assertSame($run->getKey(), SerproSyncRun::query()->findOrFail($response->json('data.id'))->previous_run_id);
    }

    public function test_resync_de_execucao_em_andamento_responde_409(): void
    {
        Queue::fake();
        $account = $this->contaPronta();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->postJson("/api/serpro/sync-runs/{$run->getKey()}/resync")
            ->assertStatus(409);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    /**
     * Conta habilitada e credencial íntegra, pelos caminhos de verdade:
     * `set()` passa pelo gate da credencial, e não pelo `forceFill`.
     */
    private function contaPronta(): Account
    {
        $account = Account::factory()->create();
        $this->conexao();
        resolve(SerproAccountEnablement::class)->set($account->getKey(), true);

        return $account->refresh();
    }

    /**
     * A credencial é uma só por plataforma (`singleton` único): a segunda
     * conta pronta do mesmo teste usa a que já existe.
     */
    private function conexao(): void
    {
        if (SerproConnection::current() !== null) {
            return;
        }

        SerproConnection::factory()->create([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
        ]);
    }
}
