<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproFailure;
use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Jobs\SyncSerproClientJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproConnection;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Os desfechos que a execução tem de ser honesta sobre: resultado
 * indeterminado, recusa que não se repete, janela de throttling e execução
 * abandonada pelo worker.
 */
class SerproSyncFailureTest extends TestCase
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

    public function test_o_504_com_identificador_vira_indeterminado_sem_repetir_e_a_execucao_fica_parcial(): void
    {
        [$account, $run] = $this->contaComExecucao();
        $vitima = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);
        $ok = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11122233344455',
        ]);

        foreach ([$vitima, $ok] as $client) {
            SerproSyncRunItem::factory()->create([
                'account_id' => $account->getKey(),
                'run_id' => $run->getKey(),
                'client_id' => $client->getKey(),
            ]);
        }

        $oracle = '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":["Caixa Postal - Mensagens"]}]';

        // O 504 bate em qualquer chamada do contribuinte da vítima: o gateway
        // respondeu — e com `responseId` — mas o resultado não voltou.
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008, 'access_token' => 'access-1', 'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => function ($request) use ($oracle, $vitima) {
                if (($request->data()['contribuinte']['numero'] ?? null) === $vitima->tax_id) {
                    return Http::response(['code' => '058', 'responseId' => 'resp-504'], 504);
                }

                return Http::response([
                    'status' => 200,
                    'dados' => $oracle,
                    'mensagens' => [],
                    'responseId' => 'resp-ok',
                ]);
            },
            '*' => Http::response(['status' => 200, 'dados' => '{}', 'mensagens' => []]),
        ]);

        $jobVitima = new SyncSerproClientJob($run->getKey(), $account->getKey(), $vitima->getKey());
        $jobVitima->handle();
        // A reentrega enxerga o item decidido: uma chamada só saiu para o
        // contribuinte do 504.
        $jobVitima->handle();

        $itemVitima = $this->item($run, $vitima);
        $this->assertSame(SerproSyncItemState::Indeterminate, $itemVitima->state);
        $this->assertSame('resp-504', $itemVitima->response_id);
        // Uma tentativa só: o provedor pode ter aplicado, e reenviar
        // cobraria de novo o que ninguém sabe se aconteceu.
        $this->assertSame(1, SerproCall::query()
            ->where('client_id', $vitima->getKey())->count());

        $jobOk = new SyncSerproClientJob($run->getKey(), $account->getKey(), $ok->getKey());
        $jobOk->handle();
        $jobOk->handle();
        $jobOk->handle();

        $this->assertSame(SerproSyncItemState::Synchronized, $this->item($run, $ok)->state);

        $run->refresh();
        $this->assertSame(SerproSyncRunState::Partial, $run->state);
        $this->assertSame(2, $run->total);
        $this->assertSame(1, $run->indeterminate);
        // O incerto não é falha: contá-lo como `failed` mentiria sobre a
        // resposta que o provedor pode ter aplicado.
        $this->assertSame(0, $run->failed);
        $this->assertSame(1, $run->synchronized);
    }

    #[DataProvider('codigosDeRecusa')]
    public function test_a_recusa_do_provedor_termina_o_item_sem_reenvio(string $codigo): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();

        $provider = "AcessoNegado-ICGERENCIADOR-{$codigo}";

        Http::fake([
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 403,
                'dados' => null,
                'mensagens' => [['codigo' => $provider, 'texto' => 'Recusado pelo provedor.']],
                'responseId' => 'resp-recusa',
            ], 403),
        ]);

        (new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey()))->handle();

        $item = $this->item($run, $client);
        $this->assertSame(SerproSyncItemState::Failed, $item->state);
        $this->assertSame($provider, $item->provider_code);
        $this->assertSame('resp-recusa', $item->response_id);
        $this->assertSame(SerproFailure::DoNotRetry->value, $item->reason);

        // Uma tentativa por código: falha de dados/permissão não se
        // resolve reenviando o mesmo pedido.
        Http::assertSentCount(1);
    }

    /**
     * As classes que o plano proíbe de retentativa: dados (-016),
     * permissão (-019) e configuração (-054). `-022` tem caminho próprio —
     * é causa do painel e não falha — e está no teste dedicado.
     *
     * @return array<string, array{0: string}>
     */
    public static function codigosDeRecusa(): array
    {
        return [
            'dados' => ['016'],
            'permissao' => ['019'],
            'configuracao' => ['054'],
        ];
    }

    public function test_o_022_marca_a_obrigacao_com_a_causa_e_continua_a_execucao(): void
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
        for ($i = 0; $i < 6; $i++) {
            $job->handle();
        }

        $item = $this->item($run, $client);
        $this->assertSame(SerproSyncItemState::Skipped, $item->state);

        // O oráculo concedeu a família, mas o serviço respondeu que a outorga
        // não cobre a caixa postal: a causa é a do provedor, e o painel a vê.
        $this->assertDatabaseHas('serpro_monitorings', [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'caixas-postais/e-cac',
            'cause' => 'sem_procuracao',
        ]);

        $call = SerproCall::query()->where('id_servico', 'MSGCONTRIBUINTE61')->sole();
        $this->assertSame(SerproFailure::DoNotRetry, $call->status);
        $this->assertSame('AcessoNegado-ICGERENCIADOR-022', $call->provider_code);
        Http::assertSentCount(2);
    }

    public function test_o_429_registra_a_tentativa_e_a_proxima_entrega_reenvia(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();

        $oracle = '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":["Caixa Postal - Mensagens"]}]';

        Http::fake([
            '*/integra-contador/v1/Consultar' => function ($request) use ($oracle) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                if ($servico === 'MSGCONTRIBUINTE61') {
                    return Http::response(['code' => '001', 'message' => 'limite'], 429);
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

        $item = $this->item($run, $client);
        // Throttled é janela, não desfecho: o item continua aberto, a
        // fronteira está limpa e cada tentativa fica no registro.
        $this->assertSame(SerproSyncItemState::NotProcessed, $item->state);
        $this->assertNull($item->current_obligation);
        $this->assertSame(2, SerproCall::query()
            ->where('id_servico', 'MSGCONTRIBUINTE61')
            ->where('status', SerproFailure::Throttled)
            ->count());
        Http::assertSentCount(3);
    }

    public function test_o_503_sem_identificador_e_incerteza_e_com_identificador_e_janela(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();

        $oracle = '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":["Caixa Postal - Mensagens"]}]';
        $comId = true;

        Http::fake([
            '*/integra-contador/v1/Consultar' => function ($request) use ($oracle, &$comId) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                if ($servico === 'MSGCONTRIBUINTE61') {
                    // Com `responseId` o provedor recebeu e registrou — a
                    // retentativa é segura; sem ele, ninguém sabe se a
                    // requisição chegou e a resposta honesta é `indeterminado`.
                    return $comId
                        ? Http::response(['code' => '058', 'responseId' => 'resp-503'], 503)
                        : Http::response(['code' => '058'], 503);
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

        // 503 com identificador é janela: item aberto, fronteira limpa.
        $this->assertSame(SerproSyncItemState::NotProcessed, $this->item($run, $client)->state);

        $comId = false;
        $job->handle();

        $item = $this->item($run, $client);
        $this->assertSame(SerproSyncItemState::Indeterminate, $item->state);
        $this->assertSame('resposta_incerta', $item->reason);
    }

    public function test_o_watchdog_falha_a_execucao_parada_e_preserva_os_itens(): void
    {
        [$account] = $this->contaComExecucao();

        $parada = SerproSyncRun::factory()->create([
            'account_id' => $account->getKey(),
            'state' => SerproSyncRunState::Running,
            'started_at' => now()->subMinutes(10),
        ]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $parada->getKey(),
            'client_id' => Client::factory()->company()->create(['account_id' => $account->getKey()])->getKey(),
        ]);
        $parada->items()->update(['updated_at' => now()->subMinutes(6)]);
        $parada->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        // Execução com progresso recente — um item moveu-se — não é
        // abandono: o watchdog não pode derrubar trabalho vivo.
        $viva = SerproSyncRun::factory()->create([
            'account_id' => $account->getKey(),
            'state' => SerproSyncRunState::Running,
            'started_at' => now()->subMinutes(10),
        ]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $viva->getKey(),
            'client_id' => Client::factory()->company()->create(['account_id' => $account->getKey()])->getKey(),
        ]);
        $viva->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        $naFila = SerproSyncRun::factory()->create([
            'account_id' => $account->getKey(),
            'state' => SerproSyncRunState::Queued,
        ]);
        $naFila->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        Artisan::call('serpro:fail-abandoned');

        $parada->refresh();
        $this->assertSame(SerproSyncRunState::Failed, $parada->state);
        $this->assertNotNull($parada->reason);
        $this->assertNotNull($parada->finished_at);
        // Os itens preservam o que eram: `nao_processado` é o que de fato
        // aconteceu com eles — nunca foram reivindicados.
        $this->assertSame(SerproSyncItemState::NotProcessed, $parada->items()->sole()->state);

        $this->assertSame(SerproSyncRunState::Running, $viva->refresh()->state);
        $this->assertSame(SerproSyncRunState::Queued, $naFila->refresh()->state);
    }

    public function test_a_falha_do_trabalhador_marca_o_item_sem_o_texto_da_excecao(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        Http::fake();

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->failed(new RuntimeException('segredo-chave-123 estourou'));

        $item = $this->item($run, $client);
        $this->assertSame(SerproSyncItemState::Failed, $item->state);
        // O texto da exceção pode carregar senha e documento: o motivo é a
        // frase fixa, e o detalhe fica no log do worker.
        $this->assertStringNotContainsString('segredo-chave-123', (string) $item->reason);
    }

    public function test_a_falha_do_trabalhador_com_fronteira_aberta_e_incerteza(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        $item = $this->item($run, $client);
        $item->forceFill([
            'current_obligation' => 'caixas-postais/e-cac',
            'attempted_at' => now(),
        ])->save();

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->failed(new RuntimeException('worker morto'));

        $this->assertSame(SerproSyncItemState::Indeterminate, $item->refresh()->state);
    }

    /**
     * Conta pronta, um PJ e a execução com item aberto — o mesmo cenário
     * do teste de jobs.
     *
     * @return array{0: Account, 1: Client, 2: SerproSyncRun}
     */
    private function cenarioChamavel(): array
    {
        [$account, $run] = $this->contaComExecucao();

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return [$account, $client, $run];
    }

    /**
     * @return array{0: Account, 1: SerproSyncRun}
     */
    private function contaComExecucao(): array
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

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        // `running` é o estado em que o fan-out deixa a execução: é só nela
        // que o finalizador calcula o desfecho terminal.
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->getKey()]);

        return [$account, $run];
    }

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
