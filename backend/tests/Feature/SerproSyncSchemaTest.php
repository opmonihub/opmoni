<?php

namespace Tests\Feature;

use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Http\Resources\SerproCallResource;
use App\Http\Resources\SerproSyncRunItemResource;
use App\Http\Resources\SerproSyncRunResource;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproCall;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A estrutura que sustenta a sincronização: execuções, itens por cliente e
 * o registro de cada chamada, com a unicidade e o isolamento que os jobs
 * daqui para frente assumem sem conferir de novo.
 */
class SerproSyncSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_par_execucao_cliente_e_unico(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
        ]);

        // A idempotência do job filho mora aqui: a segunda entrega do mesmo
        // cliente na mesma execução é atualização, e não linha nova.
        $this->expectException(QueryException::class);

        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
        ]);
    }

    public function test_o_item_conhece_a_execucao_e_o_cliente(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);
        $item = SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
        ]);

        $this->assertTrue($item->run->is($run));
        $this->assertTrue($item->client->is($client));
        $this->assertSame(SerproSyncItemState::NotProcessed, $item->state);
    }

    public function test_a_chamada_sem_cliente_e_a_da_conta(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        // A emissão do termo e o teste de conectividade são chamadas da conta
        // como um todo: `client_id` é nullable justamente para elas existirem.
        $call = SerproCall::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => null,
        ]);

        $this->assertDatabaseHas('serpro_calls', [
            'id' => $call->getKey(),
            'client_id' => null,
        ]);
    }

    public function test_a_chamada_precisa_de_uma_conta_que_existe(): void
    {
        $account = Account::factory()->create();
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        // A guarda do escopo não existe fora do tenant: é a FK, e não a
        // memória do programador, que impede uma chamada de ficar órfã de
        // conta.
        $this->expectException(QueryException::class);

        SerproCall::factory()->create([
            'account_id' => $account->getKey() + 999,
            'run_id' => $run->getKey(),
            'client_id' => null,
        ]);
    }

    public function test_a_resource_da_execucao_expoe_os_seis_contadores(): void
    {
        $run = SerproSyncRun::factory()->create([
            'state' => SerproSyncRunState::Completed,
            'total' => 9,
            'synchronized' => 5,
            'skipped' => 1,
            'failed' => 1,
            'indeterminate' => 1,
            'not_processed' => 1,
        ]);

        $payload = (new SerproSyncRunResource($run))->resolve();

        $this->assertSame(9, $payload['total']);
        $this->assertSame(5, $payload['synchronized']);
        $this->assertSame(1, $payload['skipped']);
        $this->assertSame(1, $payload['failed']);
        $this->assertSame(1, $payload['indeterminate']);
        $this->assertSame(1, $payload['not_processed']);
        $this->assertSame('completed', $payload['state']);
        $this->assertArrayNotHasKey('dados', $payload);
    }

    public function test_a_resource_do_item_expoe_a_obrigacao_em_andamento(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);
        $item = SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'state' => SerproSyncItemState::Skipped,
            'current_obligation' => 'pgdasd',
            'reason' => 'sem_procuracao',
            'request_tag' => str_repeat('a', 32),
        ]);

        $payload = (new SerproSyncRunItemResource($item))->resolve();

        // `current_obligation` é o serviço em andamento no item, e é o que a
        // tela mostra como a obrigação do item — o nome da coluna é interno.
        $this->assertSame('pgdasd', $payload['obligation']);
        $this->assertSame('ignorado', $payload['state']);
        $this->assertSame('sem_procuracao', $payload['reason']);
        $this->assertSame($client->getKey(), $payload['client_id']);
        $this->assertSame($client->name, $payload['name']);
    }

    public function test_a_resource_da_chamada_expoe_metadados_e_nunca_payload(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);
        $call = SerproCall::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'id_sistema' => 'SITFIS',
            'id_servico' => 'RELATORIOSITFIS92',
        ]);

        $payload = (new SerproCallResource($call))->resolve();

        foreach (['client_id', 'id_sistema', 'id_servico', 'version', 'path', 'billable', 'status', 'provider_code', 'response_id', 'request_tag', 'messages', 'duration_ms'] as $key) {
            $this->assertArrayHasKey($key, $payload, "a chamada precisa expor {$key}");
        }

        // O que a chamada mandou e o que o provedor devolveu ficam no banco
        // auditável, e não na resposta: `dados` carrega o documento do
        // cliente dentro do envelope.
        $this->assertArrayNotHasKey('dados', $payload);
        $this->assertArrayNotHasKey('envelope', $payload);
    }
}
