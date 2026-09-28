<?php

namespace Tests\Unit;

use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Services\SerproRunFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A recontagem que fecha a execução: os seis contadores têm de somar o total,
 * `nao_processado` é o que mantém a execução viva, e o estado terminal é
 * decidido pelo que os itens dizem — e não por quem chamou por último.
 */
class SerproRunFinalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_os_seis_contadores_somam_o_total(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);

        foreach ([
            SerproSyncItemState::Synchronized,
            SerproSyncItemState::Indeterminate,
            SerproSyncItemState::NotProcessed,
        ] as $state) {
            $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
            SerproSyncRunItem::factory()->create([
                'account_id' => $account->getKey(),
                'run_id' => $run->getKey(),
                'client_id' => $client->getKey(),
                'state' => $state,
            ]);
        }

        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());
        $actual = $run->fresh();

        $this->assertSame([3, 1, 0, 0, 1, 1], array_map(
            fn (string $key): int => $actual->{$key},
            ['total', 'synchronized', 'skipped', 'failed', 'indeterminate', 'not_processed'],
        ));

        // Ainda há `nao_processado`: a execução continua correndo.
        $this->assertSame(SerproSyncRunState::Running, $actual->state);
        $this->assertNull($actual->finished_at);
    }

    public function test_enquanto_esta_na_fila_a_recontagem_nao_termina_nada(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->create([
            'account_id' => $account->getKey(),
            'state' => SerproSyncRunState::Queued,
        ]);

        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());

        // Uma execução `queued` sem itens não é `completed`: é uma execução
        // que o fan-out ainda nem abriu, e dizer "concluída" apagaria a fila.
        $this->assertSame(SerproSyncRunState::Queued, $run->fresh()->state);
    }

    public function test_sem_nao_processado_e_sem_falha_a_execucao_completa(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);

        foreach ([SerproSyncItemState::Synchronized, SerproSyncItemState::Skipped] as $state) {
            $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
            SerproSyncRunItem::factory()->create([
                'account_id' => $account->getKey(),
                'run_id' => $run->getKey(),
                'client_id' => $client->getKey(),
                'state' => $state,
            ]);
        }

        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());
        $actual = $run->fresh();

        // O ignorado por falta de procuração é uma decisão, e não uma falha:
        // a execução que só tem sincronizados e ignorados está completa.
        $this->assertSame(SerproSyncRunState::Completed, $actual->state);
        $this->assertNotNull($actual->finished_at);
    }

    public function test_mistura_de_resultados_termina_parcial(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);

        foreach ([SerproSyncItemState::Synchronized, SerproSyncItemState::Failed] as $state) {
            $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
            SerproSyncRunItem::factory()->create([
                'account_id' => $account->getKey(),
                'run_id' => $run->getKey(),
                'client_id' => $client->getKey(),
                'state' => $state,
            ]);
        }

        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());

        $this->assertSame(SerproSyncRunState::Partial, $run->fresh()->state);
    }

    public function test_indeterminado_nao_e_falha_e_termina_parcial(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);

        foreach ([SerproSyncItemState::Synchronized, SerproSyncItemState::Indeterminate] as $state) {
            $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
            SerproSyncRunItem::factory()->create([
                'account_id' => $account->getKey(),
                'run_id' => $run->getKey(),
                'client_id' => $client->getKey(),
                'state' => $state,
            ]);
        }

        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());
        $actual = $run->fresh();

        // Indeterminado não é falha do cliente (`failed` = 0) e também não é
        // sucesso: a execução que o carrega é `partial`, nunca `completed`.
        $this->assertSame(0, $actual->failed);
        $this->assertSame(1, $actual->indeterminate);
        $this->assertSame(SerproSyncRunState::Partial, $actual->state);
    }

    public function test_nada_respondido_termina_falha_com_motivo(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);

        foreach ([SerproSyncItemState::Failed, SerproSyncItemState::Indeterminate] as $state) {
            $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
            SerproSyncRunItem::factory()->create([
                'account_id' => $account->getKey(),
                'run_id' => $run->getKey(),
                'client_id' => $client->getKey(),
                'state' => $state,
            ]);
        }

        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());
        $actual = $run->fresh();

        // Nenhum cliente entregou nada aproveitável: é a execução que não
        // saiu do lugar — credencial recusada, provedor fora —, e ela diz isso.
        $this->assertSame(SerproSyncRunState::Failed, $actual->state);
        $this->assertNotNull($actual->reason);
        $this->assertNotNull($actual->finished_at);
    }

    public function test_itens_de_outra_conta_nao_entram_na_contagem(): void
    {
        $account = Account::factory()->create();
        $outra = Account::factory()->create();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);
        $runAlheio = SerproSyncRun::factory()->running()->create(['account_id' => $outra->getKey()]);

        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $alheio = Client::factory()->company()->create(['account_id' => $outra->getKey()]);

        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'state' => SerproSyncItemState::Synchronized,
        ]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $outra->getKey(),
            'run_id' => $runAlheio->getKey(),
            'client_id' => $alheio->getKey(),
            'state' => SerproSyncItemState::Failed,
        ]);

        // O `account_id` explícito é a barreira: no worker o escopo global não
        // filtra nada, e uma execução que contasse o item alheio publicaria a
        // carteira de outro escritório.
        resolve(SerproRunFinalizer::class)->recount($run->getKey(), $account->getKey());

        $this->assertSame([1, 1, 0, 0, 0, 0], array_map(
            fn (string $key): int => $run->fresh()->{$key},
            ['total', 'synchronized', 'skipped', 'failed', 'indeterminate', 'not_processed'],
        ));
    }
}
