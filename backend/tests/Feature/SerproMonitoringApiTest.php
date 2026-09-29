<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Jobs\FanOutSerproRunJob;
use App\Jobs\SyncSerproClientJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Models\SerproMonitoring;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Models\Tag;
use App\Models\User;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A API de leitura do monitoramento: overview e listagem por obrigação, com
 * os contadores agregados antes dos filtros e o isolamento por conta no
 * `account_id` explícito — nunca no escopo global, porque a query e o
 * isolamento são a mesma decisão.
 */
class SerproMonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_overview_conta_so_clientes_pj_com_registro_sincronizado(): void
    {
        $account = Account::factory()->create();
        $pj = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $pf = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $outro = Client::factory()->company()->create();

        $this->linha($pj, 'declaracoes/pgdas');
        $this->linha($pf, 'declaracoes/pgdas');
        $this->linha($outro, 'declaracoes/pgdas');
        // Linha associada e nunca sincronizada: `source_at` nulo não é dado.
        $this->linha($pj, 'simples-nacional', ['source_at' => null]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/overview')
            ->assertOk()
            ->assertJsonPath('data.portfolio_total', 1);
    }

    public function test_overview_agrega_atencao_por_obrigacao(): void
    {
        $account = Account::factory()->create();
        $a = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $b = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->linha($a, 'declaracoes/pgdas', ['cause' => 'sem_declaracao']);
        $this->linha($b, 'declaracoes/pgdas');
        $this->linha($b, 'caixas-postais/e-cac', ['cause' => 'sem_procuracao']);

        $response = $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson('/api/serpro/monitoring/overview')
            ->assertOk();

        $this->assertSame(1, $response->json('data.attention.declaracoes/pgdas'));
        $this->assertSame(1, $response->json('data.attention.caixas-postais/e-cac'));
        // Obrigação sem fonte não recebe nem zero: o painel dela não existe.
        $this->assertArrayNotHasKey('declaracoes/dirf', $response->json('data.attention'));
    }

    public function test_a_listagem_traz_contadores_linha_e_aritmetica(): void
    {
        $account = Account::factory()->create();
        $a = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $b = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->linha($a, 'declaracoes/pgdas', ['cause' => 'sem_declaracao']);
        $this->linha($b, 'declaracoes/pgdas', ['due_on' => today()->addDays(10)->toDateString()]);

        $response = $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.obligation', 'declaracoes/pgdas')
            ->assertJsonPath('data.category', 'direct')
            ->assertJsonPath('data.atencao', 1)
            ->assertJsonPath('data.pendencias', 1)
            ->assertJsonPath('data.em_dia', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonCount(2, 'data_rows');

        $row = collect($response->json('data_rows'))->firstWhere('client_id', $a->getKey());
        $this->assertSame('atencao', $row['situacao']);
        $this->assertSame('sem_declaracao', $row['cause']);
        $this->assertSame($a->name, $row['name']);
        $this->assertSame($a->tax_id, $row['tax_id']);
    }

    public function test_o_filtro_de_situacao_nao_muda_os_contadores(): void
    {
        $account = Account::factory()->create();
        $a = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $b = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->linha($a, 'declaracoes/pgdas', ['cause' => 'sem_declaracao']);
        $this->linha($b, 'declaracoes/pgdas');

        // Os contadores descrevem a obrigação; o filtro estreita a visão. Um
        // `data.atencao` zerado pelo filtro diria "ninguém precisa de
        // atenção" ao lado de duas linhas em atenção.
        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas?situacao=atencao')
            ->assertOk()
            ->assertJsonPath('data.atencao', 1)
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(1, 'data_rows')
            ->assertJsonPath('data_rows.0.client_id', $a->getKey());
    }

    public function test_busca_e_tag_filtram_antes_da_paginacao(): void
    {
        $account = Account::factory()->create();
        $alfa = Client::factory()->company()->create(['account_id' => $account->getKey(), 'name' => 'Padaria Alfa']);
        $beta = Client::factory()->company()->create(['account_id' => $account->getKey(), 'name' => 'Oficina Beta']);
        $gama = Client::factory()->company()->create(['account_id' => $account->getKey(), 'name' => 'Padaria Gama']);

        $this->linha($alfa, 'declaracoes/pgdas');
        $this->linha($beta, 'declaracoes/pgdas');
        $this->linha($gama, 'declaracoes/pgdas');

        $tag = Tag::factory()->create(['account_id' => $account->getKey()]);
        $gama->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);
        $alfa->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);

        $member = $this->memberOf($account, 'user');

        $porNome = $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas?q=padaria')
            ->assertOk();
        $this->assertSame(
            [$alfa->getKey(), $gama->getKey()],
            collect($porNome->json('data_rows'))->sort()->pluck('client_id')->values()->all(),
        );

        $porTag = $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas?'.http_build_query(['tag_id' => [$tag->getKey()]]))
            ->assertOk();
        $this->assertSame(
            [$alfa->getKey(), $gama->getKey()],
            collect($porTag->json('data_rows'))->sort()->pluck('client_id')->values()->all(),
        );
    }

    public function test_pagina_dois_nao_repete_a_primeira(): void
    {
        $account = Account::factory()->create();

        foreach (range(1, 26) as $i) {
            $this->linha(
                Client::factory()->company()->create(['account_id' => $account->getKey()]),
                'declaracoes/pgdas',
            );
        }

        $member = $this->memberOf($account, 'user');
        $primeira = $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertJsonCount(25, 'data_rows')
            ->assertJsonPath('data.total', 26);
        $segunda = $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas?page=2')
            ->assertJsonCount(1, 'data_rows')
            ->assertJsonPath('data.current_page', 2);

        $ids = collect($primeira->json('data_rows'))->pluck('client_id')
            ->merge(collect($segunda->json('data_rows'))->pluck('client_id'));
        $this->assertSame(26, $ids->unique()->count());
    }

    public function test_as_causas_contam_exatamente_as_linhas(): void
    {
        $account = Account::factory()->create();

        $this->linha(Client::factory()->company()->create(['account_id' => $account->getKey()]), 'declaracoes/pgdas', ['cause' => 'sem_declaracao']);
        $this->linha(Client::factory()->company()->create(['account_id' => $account->getKey()]), 'declaracoes/pgdas', ['cause' => 'sem_declaracao']);
        $this->linha(Client::factory()->company()->create(['account_id' => $account->getKey()]), 'declaracoes/pgdas', ['cause' => 'sem_procuracao']);

        $response = $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk();

        $reasons = collect($response->json('data.attention_reasons'))->keyBy('code');
        $this->assertSame(2, $reasons['sem_declaracao']['count']);
        $this->assertSame(1, $reasons['sem_procuracao']['count']);
        // O rótulo é do backend: a tela o usa quando não conhece o código.
        $this->assertNotSame('', (string) $reasons['sem_declaracao']['label']);
    }

    public function test_slug_ou_situacao_inexistentes_respondem_404(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/inexistente')
            ->assertNotFound();

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas?situacao=inexistente')
            ->assertNotFound();
    }

    public function test_obrigacao_sem_fonte_nao_gera_linha_nem_contador(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        // Nem um registro manual a faz aparecer: `unavailable` e `extinct` são
        // fatos da obrigação, e uma linha ali fingiria dado que o provedor
        // não publica.
        $this->linha($client, 'declaracoes/dirf');

        $response = $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/dirf')
            ->assertOk()
            ->assertJsonPath('data.category', 'extinct')
            ->assertJsonCount(0, 'data_rows');

        // O contador é `null` — e não zero: `0` diria que nenhum cliente
        // precisa de atenção numa obrigação que o provedor não serve.
        $this->assertNull($response->json('data.total'));
        $this->assertNull($response->json('data.atencao'));

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/parcelamentos/pgfn')
            ->assertOk()
            ->assertJsonPath('data.category', 'unavailable')
            ->assertJsonCount(0, 'data_rows');
    }

    public function test_linha_associada_sem_fonte_fica_de_fora_dos_numeros(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        // A associação cria o vínculo; o que conta é o que respondeu.
        $this->linha($client, 'declaracoes/pgdas', ['source_at' => null]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data_rows');
    }

    public function test_item_em_execucao_marca_processando_mesmo_sem_fonte(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->linha($client, 'declaracoes/pgdas', ['source_at' => null]);

        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'state' => SerproSyncItemState::NotProcessed,
            'current_obligation' => 'declaracoes/pgdas',
        ]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.processando', 1)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data_rows.0.situacao', 'processando');
    }

    public function test_encerrado_fica_fora_do_total(): void
    {
        $account = Account::factory()->create();
        $a = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $b = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->linha($a, 'declaracoes/pgdas', ['state' => 'encerrado']);
        $this->linha($b, 'declaracoes/pgdas');

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.encerrado', 1)
            ->assertJsonPath('data.total', 1)
            ->assertJsonCount(2, 'data_rows');
    }

    public function test_progress_reflete_a_execucao_mais_recente(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->linha($client, 'declaracoes/pgdas');

        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);
        $run->forceFill(['total' => 3, 'synchronized' => 1])->save();

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.progress.transmitted', 1)
            ->assertJsonPath('data.progress.requested', 3);
    }

    public function test_registro_de_outra_conta_nao_aparece(): void
    {
        $account = Account::factory()->create();
        $alheio = Client::factory()->company()->create();
        $this->linha($alheio, 'declaracoes/pgdas', ['cause' => 'sem_declaracao']);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data_rows');

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->getJson('/api/serpro/monitoring/overview')
            ->assertOk()
            ->assertJsonPath('data.portfolio_total', 0);
    }

    public function test_a_execucao_completa_povoa_a_leitura_e_a_conta_vizinha_fica_vazia(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $account = $this->contaPronta();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);
        $member = $this->memberOf($account, 'operador');

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => function ($request) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                $dados = match ($servico) {
                    // O oráculo concede a caixa postal e nada mais: a
                    // outorga do PGDAS chega pela linha semeada abaixo —
                    // `SerproPowerNames` ainda não conhece o nome que a
                    // família 00146 teria na resposta, e semear antes do
                    // oráculo a marcaria como recusada.
                    'OBTERPROCURACAO41' => '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":["Caixa Postal - Mensagens"]}]',
                    'MSGCONTRIBUINTE61' => '{}',
                    // Declaração retificadora mais recente que a original,
                    // e um DAS pago: é o caminho cheio da projeção.
                    'CONSDECLARACAO13' => '{"anocalendario":2026,"periodos":[{"periodoApuracao":202601,"operacoes":['
                        .'{"tipoOperacao":"Original","indiceDeclaracao":{"numeroDeclaracao":"00000000202601001","dataHoraTransmissao":"20260220152512","malha":""},"indiceDas":null},'
                        .'{"tipoOperacao":"Declaração Retificadora","indiceDeclaracao":{"numeroDeclaracao":"00000000202601002","dataHoraTransmissao":"20260301101010","malha":null},"indiceDas":null},'
                        .'{"tipoOperacao":"Geração de DAS","indiceDeclaracao":null,"indiceDas":{"numeroDas":"07202215764027873","datahoraEmissaoDas":"20260306153456","dasPago":true}}]}]}',
                    default => '{}',
                };

                return Http::response([
                    'status' => 200,
                    'dados' => $dados,
                    'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Requisição efetuada com sucesso']],
                    'responseId' => 'resp-'.$servico,
                ]);
            },
        ]);

        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);
        (new FanOutSerproRunJob($run->getKey(), $account->getKey()))->handle();

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->handle();

        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00146',
            'code' => '00146',
        ]);

        $job->handle();
        $job->handle();
        $job->handle();

        $run->refresh();
        $this->assertSame(SerproSyncRunState::Completed, $run->state);
        $this->assertSame(1, $run->synchronized);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/overview')
            ->assertOk()
            ->assertJsonPath('data.portfolio_total', 1)
            ->assertJsonPath('data.attention.declaracoes/pgdas', 0)
            ->assertJsonPath('data.attention.caixas-postais/e-cac', 0);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.em_dia', 1)
            ->assertJsonPath('data.atencao', 0)
            ->assertJsonPath('data.progress.transmitted', 1)
            ->assertJsonPath('data.progress.requested', 1)
            ->assertJsonPath('data_rows.0.client_id', $client->getKey())
            ->assertJsonPath('data_rows.0.tax_id', '33683111000875')
            ->assertJsonPath('data_rows.0.situacao', 'em_dia')
            ->assertJsonPath('data_rows.0.stale', false)
            ->assertJsonPath('data_rows.0.fields.gi_declaracao', '00000000202601002')
            ->assertJsonPath('data_rows.0.periods.0.period', '2026-01')
            ->assertJsonPath('data_rows.0.periods.0.rectified', true)
            ->assertJsonPath('data_rows.0.periods.0.slip_paid', true)
            ->assertJsonPath('data_rows.0.periods.0.slip_number', '07202215764027873');

        // A conta vizinha, com a mesma leitura, responde o vazio dela.
        $vizinha = Account::factory()->create();
        $membroDaVizinha = $this->memberOf($vizinha, 'admin');

        $this->actingAs($membroDaVizinha, 'sanctum')
            ->getJson('/api/serpro/monitoring/overview')
            ->assertOk()
            ->assertJsonPath('data.portfolio_total', 0);

        $this->actingAs($membroDaVizinha, 'sanctum')
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

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function linha(Client $client, string $obrigacao, array $atributos = []): SerproMonitoring
    {
        return SerproMonitoring::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'obligation' => $obrigacao,
            'source_at' => '2026-09-26 10:00:00',
            ...$atributos,
        ]);
    }

    /**
     * Conta com integração ligada, certificado e termo vigente: o que a fase
     * de seleção do job exige antes da primeira chamada.
     */
    private function contaPronta(): Account
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

        if (SerproConnection::current() === null) {
            SerproConnection::factory()->create([
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
                'certificate_encrypted' => Crypt::encryptString($this->pfxDaPlataforma()),
                'certificate_password_encrypted' => Crypt::encryptString('senha'),
                'contratante_numero' => '12345678000195',
                'contratante_tipo' => 2,
                'certificate_valid_until' => now()->addYear(),
            ]);
        }

        SerproAuthorizationTerm::forceCreate([
            'account_id' => $account->getKey(),
            'author_document' => '12345678000195',
            'document_encrypted' => Crypt::encryptString('<termo/>'),
            'document_expires_on' => '2027-01-01',
            'signed_at' => now(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('token-do-termo'),
            'token_expires_at' => CarbonImmutable::parse('2026-09-29'),
        ]);

        AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => '12345678000195',
        ]);

        return $account;
    }

    /**
     * O mesmo PFX que `SerproClientTest` gera em runtime: `assertIdentity()`
     * recusa certificado de mentira antes de qualquer rede.
     */
    private function pfxDaPlataforma(): string
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(
            ['CN' => 'SERPRO PLATAFORMA LTDA:12345678000195', 'serialNumber' => '12345678000195'],
            $key,
            array_merge(['digest_alg' => 'sha256'], $config),
        );
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'senha'));

        return $pfx;
    }
}
