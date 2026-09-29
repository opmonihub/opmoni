<?php

namespace Tests\Unit;

use App\Enums\SerproPowerOfAttorneyState;
use App\Enums\SerproSyncItemState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproClientAuthorization;
use App\Models\SerproMonitoring;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Services\SerproMonitoringProjector;
use App\Services\SerproObligationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A projeção de uma linha sincronizada para o contrato da tela: situação,
 * causa, staleness e as colunas de leitura. `RefreshDatabase` de propósito:
 * a linha é de factory porque os casts dela são o que se projeta.
 */
class SerproMonitoringProjectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ausencia_de_declaracao_e_atencao_com_a_causa_guardada(): void
    {
        $linha = $this->linha(['cause' => 'sem_declaracao']);

        $row = $this->projector()->row($linha->client, $linha, $this->concessaoVigente($linha->client), null);

        $this->assertSame('atencao', $row['situacao']);
        $this->assertSame('sem_declaracao', $row['cause']);
    }

    public function test_sem_procuracao_e_contam_debitos_chegam_como_causa(): void
    {
        foreach (['sem_procuracao', 'contam_debitos'] as $cause) {
            $linha = $this->linha(['cause' => $cause]);

            $row = $this->projector()->row($linha->client, $linha, null, null);

            $this->assertSame('atencao', $row['situacao']);
            $this->assertSame($cause, $row['cause']);
        }
    }

    public function test_procuracao_vencida_marca_stale_sem_mudar_a_situacao(): void
    {
        $linha = $this->linha();
        $vencida = $this->concessaoVencida($linha->client);

        $row = $this->projector()->row($linha->client, $linha, $vencida, null);

        // A procuração expirada não é uma causa: a situação fica a que o
        // dado retido diz, e é `stale` quem avisa que ele envelheceu.
        $this->assertSame('em_dia', $row['situacao']);
        $this->assertNull($row['cause']);
        $this->assertTrue($row['stale']);
        $this->assertSame('2026-09-20', $row['power_of_attorney_expires_on']);
    }

    public function test_concessao_vigente_nao_sinaliza_nada(): void
    {
        $linha = $this->linha();

        $row = $this->projector()->row($linha->client, $linha, $this->concessaoVigente($linha->client), null);

        $this->assertFalse($row['stale']);
        $this->assertSame('2027-01-01', $row['power_of_attorney_expires_on']);
    }

    public function test_item_em_processamento_marca_processando_para_a_mesma_obrigacao(): void
    {
        $linha = $this->linha(['cause' => 'sem_declaracao']);
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $linha->account_id]);
        $item = SerproSyncRunItem::factory()->create([
            'account_id' => $linha->account_id,
            'run_id' => $run->getKey(),
            'client_id' => $linha->client_id,
            'state' => SerproSyncItemState::NotProcessed,
            'current_obligation' => 'declaracoes/pgdas',
        ]);

        $row = $this->projector()->row($linha->client, $linha, null, $item);

        // `processando` precede até a causa: a linha está sendo respondida
        // agora, e a resposta nova é o que decidirá o desfecho.
        $this->assertSame('processando', $row['situacao']);
    }

    public function test_item_de_outra_obrigacao_nao_marca_processando(): void
    {
        $linha = $this->linha();
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $linha->account_id]);
        $item = SerproSyncRunItem::factory()->create([
            'account_id' => $linha->account_id,
            'run_id' => $run->getKey(),
            'client_id' => $linha->client_id,
            'state' => SerproSyncItemState::NotProcessed,
            'current_obligation' => 'simples-nacional',
        ]);

        $row = $this->projector()->row($linha->client, $linha, null, $item);

        $this->assertSame('em_dia', $row['situacao']);
    }

    public function test_prazo_em_trinta_dias_e_pendencia_e_em_trinta_e_um_e_em_dia(): void
    {
        $perto = $this->linha(['due_on' => '2026-10-27']);
        $longe = $this->linha(['due_on' => '2026-10-28']);
        $vencido = $this->linha(['due_on' => '2026-09-26']);

        $projector = $this->projector();

        $this->assertSame('pendencias', $projector->row($perto->client, $perto, null, null)['situacao']);
        $this->assertSame('em_dia', $projector->row($longe->client, $longe, null, null)['situacao']);
        $this->assertSame('pendencias', $projector->row($vencido->client, $vencido, null, null)['situacao']);
    }

    public function test_encerrado_fica_fora_dos_quatro(): void
    {
        $linha = $this->linha(['state' => 'encerrado', 'due_on' => '2026-10-01']);

        $row = $this->projector()->row($linha->client, $linha, null, null);

        $this->assertSame('encerrado', $row['situacao']);
    }

    public function test_a_linha_traz_cliente_campos_e_periodos_do_registro(): void
    {
        $linha = $this->linha([
            'fields' => ['gi_declaracao' => '777', 'mais_paginas' => true],
            'periods' => [
                ['period' => '2026-08', 'declared_at' => '2026-09-03', 'rectified' => false, 'slip_number' => '55', 'slip_issued_at' => '2026-09-03', 'due_on' => null, 'slip_paid' => true],
            ],
            'messages' => null,
            'due_on' => '2026-11-10',
        ]);

        $row = $this->projector()->row($linha->client, $linha, null, null);

        $this->assertSame($linha->client_id, $row['client_id']);
        $this->assertSame($linha->client->name, $row['name']);
        $this->assertSame($linha->client->tax_id, $row['tax_id']);
        $this->assertSame('2026-11-10', $row['due_on']);
        $this->assertSame('777', $row['fields']['gi_declaracao']);
        // Booleano do provedor vira número no fio: `mais_paginas` é da caixa
        // postal e o contrato do TS não aceita `true`.
        $this->assertSame(1, $row['fields']['mais_paginas']);
        $this->assertSame('2026-08', $row['periods'][0]['period']);
        $this->assertNull($row['message']);
    }

    public function test_a_mensagem_da_caixa_e_o_stub_mais_recente_sem_corpo(): void
    {
        $linha = $this->linha([
            'messages' => [
                ['id' => 7, 'assunto' => 'Antiga', 'received_at' => '2026-01-01', 'lida_em' => null, 'ciencia_em' => null, 'prazo_limite' => null, 'unread' => true],
                ['id' => 9, 'assunto' => 'Recente', 'received_at' => '2026-09-20', 'lida_em' => null, 'ciencia_em' => null, 'prazo_limite' => '2026-10-05', 'unread' => true],
            ],
        ]);

        $row = $this->projector()->row($linha->client, $linha, null, null);

        $this->assertSame(9, $row['message']['id']);
        $this->assertSame('Recente', $row['message']['assunto']);
        $this->assertTrue($row['message']['unread']);
        $this->assertArrayNotHasKey('corpo', $row['message']);
    }

    public function test_obrigacao_sem_familia_nao_le_procuracao(): void
    {
        // `mei` é a única entrada sem família: a ausência de concessão não é
        // dado velho — a obrigação não pede outorga nenhuma.
        $linha = $this->linha(['obligation' => 'mei']);

        $row = $this->projector()->row($linha->client, $linha, null, null);

        $this->assertFalse($row['stale']);
        $this->assertNull($row['power_of_attorney_expires_on']);
    }

    /**
     * Uma linha sincronizada de PGDAS: `source_at` preenchido, porque é o que
     * o writer grava quando o provedor respondeu.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function linha(array $atributos = []): SerproMonitoring
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        return SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'state' => 'sem_dados',
            'source_at' => '2026-09-26 10:00:00',
            ...$atributos,
        ])->refresh();
    }

    private function concessaoVigente(Client $client): SerproClientAuthorization
    {
        return SerproClientAuthorization::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'family' => '00146',
            'state' => SerproPowerOfAttorneyState::Established,
            'expires_on' => '2027-01-01',
        ]);
    }

    private function concessaoVencida(Client $client): SerproClientAuthorization
    {
        return SerproClientAuthorization::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'family' => '00146',
            'state' => SerproPowerOfAttorneyState::Expired,
            'expires_on' => '2026-09-20',
        ]);
    }

    private function projector(): SerproMonitoringProjector
    {
        return new SerproMonitoringProjector(new SerproObligationCatalog);
    }
}
