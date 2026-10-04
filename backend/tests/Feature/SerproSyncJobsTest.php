<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Jobs\FanOutSerproRunJob;
use App\Jobs\SyncSerproClientJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Services\SerproClientLock;
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
 * O leque e o trabalhador: uma execução abre um item por cliente PJ da
 * conta, e cada item é reivindicado uma obrigação por vez, sob lock por
 * documento e sem herdar o tenant do job anterior.
 */
class SerproSyncJobsTest extends TestCase
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

    public function test_o_leque_abre_um_item_e_um_job_para_cada_pj_da_conta(): void
    {
        Queue::fake();
        $account = $this->contaPronta();
        $pj = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        $outra = Account::factory()->create();
        Client::factory()->company()->create(['account_id' => $outra->getKey()]);

        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        (new FanOutSerproRunJob($run->getKey(), $account->getKey()))->handle();

        // O PJ da conta virou item; o PF da mesma conta e o PJ alheio, não.
        $item = SerproSyncRunItem::query()
            ->where('account_id', $account->getKey())->sole();
        $this->assertSame($pj->getKey(), $item->client_id);
        $this->assertSame(SerproSyncItemState::NotProcessed, $item->state);
        $this->assertDatabaseMissing('serpro_sync_run_items', [
            'client_id' => Client::factory()->individual()->create(['account_id' => $account->getKey()])->getKey(),
        ]);
        $this->assertSame(1, SerproSyncRunItem::withoutGlobalScope('account')->count());

        Queue::assertPushed(SyncSerproClientJob::class, 1);
        Queue::assertPushed(SyncSerproClientJob::class, fn (SyncSerproClientJob $job): bool => $job->accountId === $account->getKey()
            && $job->runId === $run->getKey()
            && $job->clientId === $pj->getKey());
    }

    public function test_o_leque_marca_a_execucao_como_running(): void
    {
        Queue::fake();
        $account = $this->contaPronta();
        Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        (new FanOutSerproRunJob($run->getKey(), $account->getKey()))->handle();

        $this->assertSame(SerproSyncRunState::Running, $run->refresh()->state);
        $this->assertNotNull($run->started_at);
    }

    public function test_o_leque_nao_redespacha_item_ja_processado(): void
    {
        Queue::fake();
        $account = $this->contaPronta();
        $pj = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);

        (new FanOutSerproRunJob($run->getKey(), $account->getKey()))->handle();
        // A reentrega da mesma execução enxerga o item e não reabre trabalho.
        (new FanOutSerproRunJob($run->getKey(), $account->getKey()))->handle();

        $this->assertSame(1, SerproSyncRunItem::count());
        Queue::assertPushed(SyncSerproClientJob::class, 1);
    }

    public function test_a_entrega_processa_um_servico_e_se_reagenda(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        Http::fake($this->fakesDeSucesso());

        (new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey()))->handle();

        $item = $this->item($run, $client);
        // O oráculo de procuração corre primeiro e a entrega se reagenda —
        // um serviço por vez é o que mantém o job abaixo do timeout.
        $this->assertNull($item->current_obligation);
        $this->assertNull($item->attempted_at);
        $this->assertSame(SerproSyncItemState::NotProcessed, $item->state);
        $this->assertDatabaseHas('serpro_calls', [
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'id_servico' => 'OBTERPROCURACAO41',
        ]);
        Http::assertSentCount(1);
    }

    public function test_a_reentrega_nao_repete_o_servico_ja_registrado(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        Http::fake($this->fakesDeSucesso());

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->handle();
        $job->handle();

        // Duas entregas, duas chamadas: OBTERPROCURACAO41 e o próximo
        // serviço pendente — a mesma chamada nunca sai duas vezes na mesma
        // execução.
        $this->assertSame(2, SerproCall::count());
        $this->assertSame(
            ['OBTERPROCURACAO41', 'DIVIDAATIVA24'],
            SerproCall::query()->orderBy('id')->pluck('id_servico')->all(),
        );
        Http::assertSentCount(2);
    }

    public function test_a_tentativa_sem_resposta_do_crash_anterior_vira_indeterminado(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        $item = $this->item($run, $client);
        $item->forceFill([
            'current_obligation' => 'declaracoes/pgdas',
            'attempted_at' => now(),
        ])->save();

        Http::fake($this->fakesDeSucesso());

        (new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey()))->handle();

        // Reenviar o que talvez já tenha sido aplicado cobraria duas vezes:
        // a resposta que não chegou é `indeterminado`, e não uma retentativa.
        $this->assertSame(SerproSyncItemState::Indeterminate, $item->refresh()->state);
        Http::assertNothingSent();
    }

    public function test_o_lock_tomado_por_outro_devolve_o_job_a_fila_com_o_item_intocado(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        Http::fake($this->fakesDeSucesso());

        $lock = resolve(SerproClientLock::class);
        $ocupado = $lock->acquire($account->getKey(), (string) $client->tax_id);
        $this->assertTrue($ocupado !== null);

        $job = (new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey()))->withFakeQueueInteractions();
        $job->handle();

        // O lock diz "outra entrega está trabalhando": nem chamada, nem
        // estado novo. O job não é descartado: volta à fila e espera o lock.
        $job->assertReleased(delay: 15);
        $this->assertSame(SerproSyncItemState::NotProcessed, $this->item($run, $client)->state);
        $this->assertSame(0, SerproCall::count());
        Http::assertNothingSent();

        $ocupado->release();
    }

    public function test_o_tenant_do_job_anterior_e_restaurado_e_nao_vira_fonte(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        $outra = Account::factory()->create();
        Http::fake($this->fakesDeSucesso());

        // Um worker de fila é longo: o singleton chega sujo do job anterior.
        resolve(CurrentTenant::class)->accountId = $outra->getKey();

        (new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey()))->handle();

        $this->assertSame($outra->getKey(), resolve(CurrentTenant::class)->accountId);
        // A consulta sai sem o escopo porque o singleton restaurado é o da
        // outra conta — que é o cenário que o teste encena.
        $this->assertSame(1, SerproCall::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $account->getKey())->count());
        $this->assertSame(0, SerproCall::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $outra->getKey())->count());
    }

    public function test_cliente_sem_procuracao_sincroniza_mei_e_marca_o_resto_sem_outorga(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        // O oráculo responde "não consta": nenhuma família concedida.
        Http::fake($this->fakesDeSucesso(oracleSistemas: []));

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        for ($i = 0; $i < 8; $i++) {
            $job->handle();
        }

        $item = $this->item($run, $client);
        // O MEI respondeu sem outorga; as demais obrigações ficaram com causa.
        $this->assertSame(SerproSyncItemState::Synchronized, $item->state);
        $this->assertSame(
            ['OBTERPROCURACAO41', 'DIVIDAATIVA24'],
            SerproCall::query()->orderBy('id')->pluck('id_servico')->all(),
        );
        Http::assertSentCount(2);

        $this->assertDatabaseHas('serpro_monitorings', [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'cause' => 'sem_procuracao',
        ]);
    }

    public function test_cliente_com_autorizacao_gravada_nao_consulta_o_oraculo_de_novo(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();

        // A linha gravada é a prova de que o provedor já respondeu por este
        // cliente: a `OBTERPROCURACAO41` é cobrada, e reconsultá-la seria
        // pagar para reler o que o banco sabe.
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00006',
        ]);

        Http::fake($this->fakesDeSucesso());

        (new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey()))->handle();

        $this->assertDatabaseMissing('serpro_calls', [
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
            'id_servico' => 'OBTERPROCURACAO41',
        ]);

        // E a sincronização segue direto para as obrigações: o MEI não exige
        // outorga e vem antes da caixa postal na ordem do catálogo.
        Http::assertSentCount(1);
        $this->assertSame(
            'DIVIDAATIVA24',
            SerproCall::query()->where('run_id', $run->getKey())->sole()->id_servico,
        );
    }

    public function test_a_recusa_022_marca_como_rejected_as_familias_da_alternativa_aceita(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();

        $oracle = '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":["Caixa Postal - Mensagens"]}]';

        Http::fake([
            '*/integra-contador/v1/Consultar' => function ($request) use ($oracle) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                if ($servico === 'MSGCONTRIBUINTE61') {
                    return Http::response([
                        'status' => 403,
                        'dados' => null,
                        'mensagens' => [[
                            'codigo' => 'AcessoNegado-ICGERENCIADOR-022',
                            'texto' => 'Não possui procuração outorgada no e-CAC para o contribuinte.',
                        ]],
                    ], 403);
                }

                return Http::response([
                    'status' => 200,
                    'dados' => $servico === 'OBTERPROCURACAO41' ? $oracle : '{}',
                    'mensagens' => [],
                    'responseId' => 'resp-ok',
                ]);
            },
        ]);

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->handle();
        $job->handle();
        $job->handle();

        // A família que o provedor concedeu no oráculo e recusou no serviço
        // sai de `established` para `rejected` — o estado que a elegibilidade
        // recusa sem rede, sem nova chamada cobrada.
        $autorizacao = SerproClientAuthorization::query()
            ->where('account_id', $account->getKey())
            ->where('client_id', $client->getKey())
            ->where('family', '00006')
            ->sole();
        $this->assertSame(SerproPowerOfAttorneyState::Rejected, $autorizacao->state);
        $this->assertNotNull($autorizacao->verified_at);

        // O painel vê a causa, e a chamada recusada não se repete.
        $this->assertDatabaseHas('serpro_monitorings', [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'caixas-postais/e-cac',
            'cause' => 'sem_procuracao',
        ]);
        Http::assertSentCount(3);
    }

    public function test_conta_desligada_no_meio_da_execucao_ignora_o_item(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        Http::fake($this->fakesDeSucesso());

        $account->forceFill(['settings' => ['serpro_enabled' => false]])->save();

        (new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey()))->handle();

        $this->assertSame(SerproSyncItemState::Skipped, $this->item($run, $client)->state);
        $this->assertSame('conta_desligada', $this->item($run, $client)->reason);
        Http::assertNothingSent();
    }

    /**
     * Conta com integração ligada, certificado, termo vigente e um PJ com
     * as procurações dos serviços habilitados: o máximo que a fase de
     * seleção deixa passar.
     *
     * @return array{0: Account, 1: Client, 2: SerproSyncRun}
     */
    private function cenarioChamavel(): array
    {
        $account = $this->contaPronta();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        // O par de tokens vai em cache: sem a semente a autenticação sairia
        // como uma segunda chamada HTTP e `assertSentCount` contaria os dois.
        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        // Nenhuma autorização pré-semeada: é o oráculo da execução que grava
        // a família `00006`, e é ela que torna a caixa postal elegível.
        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return [$account, $client, $run];
    }

    /**
     * @param  list<string>  $oracleSistemas
     * @return array<string, mixed>
     */
    private function fakesDeSucesso(array $oracleSistemas = ['Caixa Postal - Mensagens']): array
    {
        $oracle = $oracleSistemas === []
            ? '[]'
            : '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":'.json_encode($oracleSistemas).'}]';

        return [
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => function ($request) use ($oracle) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                return Http::response([
                    'status' => 200,
                    'dados' => $servico === 'OBTERPROCURACAO41' ? $oracle : '{}',
                    'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Requisição efetuada com sucesso']],
                    'responseId' => 'resp-'.$servico,
                ]);
            },
            '*' => Http::response([
                'status' => 200,
                'dados' => '{}',
                'mensagens' => [],
                'responseId' => 'resp-2',
            ]),
        ];
    }

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

    private function item(SerproSyncRun $run, Client $client): SerproSyncRunItem
    {
        return SerproSyncRunItem::query()
            ->where('run_id', $run->getKey())
            ->where('client_id', $client->getKey())
            ->firstOrFail();
    }
}
