<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Plan;
use App\Models\SerproMonitoring;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A tabela que deixou de ser placeholder: o vínculo `(conta, cliente,
 * obrigação)` é o que a projeção sincronizada guarda, e o CRUD de `name`
 * que ela substitui não pode sobrar — nem como rota, nem como limite de
 * plano que ninguém mais consulta.
 */
class SerproMonitoringMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_coluna_placeholder_nao_existe_mais(): void
    {
        $this->assertFalse(Schema::hasColumn('serpro_monitorings', 'name'));

        foreach (['client_id', 'obligation', 'state', 'cause', 'due_on', 'fields', 'periods', 'messages', 'source_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('serpro_monitorings', $column),
                "coluna ausente: {$column}"
            );
        }
    }

    public function test_o_vinculo_e_por_cliente_e_obrigacao(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'pgdasd',
            'state' => 'em_dia',
        ]);

        $this->assertDatabaseCount('serpro_monitorings', 1);
    }

    public function test_a_tripla_conta_cliente_obrigacao_e_unica(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'pgdasd',
        ]);

        // O re-sync escreve no lugar: a segunda gravação do mesmo vínculo é
        // atualização, e é o índice — e não o `updateOrCreate` — que o garante
        // quando duas execuções escrevem juntas.
        $this->expectException(QueryException::class);

        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'pgdasd',
        ]);
    }

    public function test_duas_obrigacoes_do_mesmo_cliente_coexistem(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        foreach (['pgdasd', 'dctfweb'] as $obligation) {
            SerproMonitoring::factory()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
                'obligation' => $obligation,
            ]);
        }

        $this->assertDatabaseCount('serpro_monitorings', 2);
    }

    public function test_a_rota_do_crud_placeholder_nao_existe(): void
    {
        // O CRUD de `name` nunca teve consumidor — o frontend nunca chamou
        // `/api/monitorings` — e a tabela virou vínculo interno: rota nenhuma.
        $this->assertFalse(Route::has('monitorings.index'));
        $this->assertFalse(Route::has('monitorings.store'));
        $this->assertFalse(Route::has('monitorings.update'));
        $this->assertFalse(Route::has('monitorings.destroy'));
    }

    public function test_os_planos_nao_carregam_mais_limite_de_monitoramentos(): void
    {
        $this->seed(PlanSeeder::class);

        // O que se conta agora é "cliente × obrigação", e a chave velha ficaria
        // dizendo um número que ninguém pergunta.
        foreach (Plan::all() as $plan) {
            $this->assertArrayNotHasKey('monitorings', $plan->limits ?? []);
        }

        $account = Account::factory()->create();
        $this->assertArrayNotHasKey('monitorings', $account->subscription->plan->limits ?? []);
    }
}
