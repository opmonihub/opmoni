<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalSource;
use App\Jobs\ReconcileFiscalDocumentsJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\FiscalGap;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Capture\FiscalReconciliation;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use App\Tenant\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * O comando `fiscal:reconcile` e o job que ele despacha: como a volta atrás
 * entra na fila e como ela é limitada no tempo.
 *
 * Quatro coisas são decididas aqui e em lugar nenhum mais. A reconciliação é
 * consulta como qualquer outra, então ela sai do expediente: a entrada é única
 * por dia, às duas da manhã no fuso configurado, e é ela que não espera a
 * carteira inteira. Um job por cliente e por fonte, porque um lote de consulta
 * por posição não cabe na janela do worker. Nenhum job para uma fonte que o
 * registro de conector não serve, porque o job de uma fonte sem conector
 * rodaria a consulta da outra e arquivaria o documento na fonte errada. E a
 * parada do fisco continua valendo: um cliente bloqueado não é consultado, mesmo
 * com a agenda disparando.
 *
 * `Bus::fake()` de propósito: o que se verifica no comando é o despacho, nunca
 * a fila. Rodar o job aqui seria repetir o `FiscalReconciliationTest` — com a
 * exceção dos caminhos que só o job tem, que são o cliente apagado entre o
 * despacho e a execução e a conta corrente que o worker herda do job anterior,
 * e é por isso que eles são testados chamando o `handle()` direto.
 */
class ReconcileFiscalDocumentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_despacha_um_job_por_cliente_com_lacuna_na_fonte_pedida(): void
    {
        $account = Account::factory()->create();

        $primeiro = $this->clientWithGap($account, 101);
        $this->clientWithGap($account, 102);

        // Cliente sem lacuna nenhuma: não há buraco para fechar, e um job aqui
        // seria uma execução que abre a trava, lê o cursor e não consulta nada.
        $semLacuna = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        // Lacuna de outra fonte: esta entrada é de CT-e, e o comando que roda é
        // o da NF-e. É a fonte da lacuna que decide a entrada, e não a existência
        // de qualquer lacuna do cliente.
        $outraFonte = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        FiscalGap::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $outraFonte->getKey(),
            'source' => FiscalSource::CteDistribuicao,
            'nsu' => 101,
        ]);

        $this->artisan('fiscal:reconcile')
            ->expectsOutputToContain('Reconciliações despachadas: 2')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ReconcileFiscalDocumentsJob::class, 2);
        Bus::assertDispatched(
            ReconcileFiscalDocumentsJob::class,
            fn (ReconcileFiscalDocumentsJob $job): bool => $job->clientId === $primeiro->getKey()
                && $job->source === FiscalSource::NfeDistribuicao,
        );
        Bus::assertNotDispatched(
            ReconcileFiscalDocumentsJob::class,
            fn (ReconcileFiscalDocumentsJob $job): bool => $job->clientId === $semLacuna->getKey(),
        );
        Bus::assertNotDispatched(
            ReconcileFiscalDocumentsJob::class,
            fn (ReconcileFiscalDocumentsJob $job): bool => $job->clientId === $outraFonte->getKey(),
        );
    }

    public function test_filtra_pelo_cliente_pedido(): void
    {
        $account = Account::factory()->create();

        $querido = $this->clientWithGap($account, 101);
        $this->clientWithGap($account, 102);

        $this->artisan('fiscal:reconcile', ['--client' => (string) $querido->getKey()])
            ->expectsOutputToContain('Reconciliações despachadas: 1')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ReconcileFiscalDocumentsJob::class, 1);
        Bus::assertDispatched(
            ReconcileFiscalDocumentsJob::class,
            fn (ReconcileFiscalDocumentsJob $job): bool => $job->clientId === $querido->getKey(),
        );
    }

    public function test_recusa_uma_fonte_desconhecida(): void
    {
        $this->artisan('fiscal:reconcile', ['--source' => 'nao_existe'])
            ->expectsOutputToContain('Fonte inválida')
            ->assertFailed();

        Bus::assertNotDispatched(ReconcileFiscalDocumentsJob::class);
    }

    public function test_recusa_uma_fonte_sem_conector(): void
    {
        // As duas fontes têm conector nesta versão, então a recusa é exercitada
        // com um registro que não conhece a fonte de CT-e — que é a situação de
        // um job que entrou na fila antes de o conector existir, ou de um
        // registro configurado sem aquela fonte.
        $this->bindRegistry([FiscalSource::NfeDistribuicao->value => NfeDistributionConnector::class]);

        $this->artisan('fiscal:reconcile', ['--source' => 'cte_distribuicao'])
            ->expectsOutputToContain('não tem conector')
            ->assertFailed();

        Bus::assertNotDispatched(ReconcileFiscalDocumentsJob::class);
    }

    public function test_a_conta_corrente_residua_nao_esconde_a_lacuna_de_nenhuma_conta(): void
    {
        $primeira = Account::factory()->create();
        $segunda = Account::factory()->create();

        $daPrimeira = $this->clientWithGap($primeira, 101);
        $daSegunda = $this->clientWithGap($segunda, 101);

        // A conta corrente é um singleton que o worker de fila nunca zera entre
        // jobs: quem roda a volta atrás precisa achar as lacunas de todo mundo,
        // e não só as da conta que o job anterior deixou apontada aqui.
        resolve(CurrentTenant::class)->accountId = $segunda->getKey();

        $this->artisan('fiscal:reconcile')
            ->expectsOutputToContain('Reconciliações despachadas: 2')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ReconcileFiscalDocumentsJob::class, 2);
        Bus::assertDispatched(
            ReconcileFiscalDocumentsJob::class,
            fn (ReconcileFiscalDocumentsJob $job): bool => $job->clientId === $daPrimeira->getKey(),
        );
        Bus::assertDispatched(
            ReconcileFiscalDocumentsJob::class,
            fn (ReconcileFiscalDocumentsJob $job): bool => $job->clientId === $daSegunda->getKey(),
        );
    }

    public function test_a_agenda_roda_a_volta_atras_uma_vez_ao_dia_fora_do_expediente(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'fiscal:reconcile'));

        $this->assertNotEmpty($events, 'fiscal:reconcile deve estar agendado.');

        $this->assertTrue(
            $events->every(fn ($event): bool => $event->getExpression() === '0 2 * * *'),
            'fiscal:reconcile deve rodar uma vez ao dia, às duas da manhã.',
        );
        $this->assertTrue(
            $events->every(fn ($event): bool => $event->timezone === 'America/Sao_Paulo'),
            'fiscal:reconcile deve seguir o fuso configurado.',
        );
        $this->assertTrue(
            $events->every(fn ($event): bool => $event->withoutOverlapping),
            'fiscal:reconcile deve proibir sobreposição da própria entrada.',
        );

        // A expressão vem da configuração, e é a configuração que amarra o
        // fuso: uma entrada às duas da manhã no horário de Brasília não é a
        // mesma coisa que uma entrada às duas no horário do servidor.
        $this->assertSame(2, (int) config('fiscal.reconcile_hour'));
        $this->assertSame('America/Sao_Paulo', (string) config('fiscal.reconcile_timezone'));
    }

    public function test_o_job_recusa_a_fonte_que_o_registro_nao_serve(): void
    {
        $account = Account::factory()->create();
        $client = $this->clientWithGap($account, 101, FiscalSource::CteDistribuicao);

        // Registro sem a fonte de CT-e, e o conector de NF-e registrado: a
        // lacuna é de CT-e e não há conector para ela, então consultar seria
        // pedir a posição de um serviço pelo outro.
        $connector = $this->bindConnector(FiscalSource::NfeDistribuicao);

        $this->runJob((int) $client->getKey(), FiscalSource::CteDistribuicao);

        // A lacuna fica pendente e sem contagem, que é o que acontece com uma
        // posição que ninguém perguntou.
        $this->assertSame([], $connector->lookups);
        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);
    }

    public function test_cliente_bloqueado_ate_amanha_nao_e_consultado(): void
    {
        $account = Account::factory()->create();
        $client = $this->clientWithGap($account, 101);

        // A parada do fisco é de dono, não de tempo: vale para a captura e vale
        // para a volta atrás, e retomar antes de completar a hora zera a
        // contagem e reinicia.
        $this->cursor($client, ['blocked_until' => now()->addDay()]);

        $connector = $this->bindConnector();

        $this->runJob((int) $client->getKey(), FiscalSource::NfeDistribuicao);

        $this->assertSame([], $connector->lookups);
        $this->assertSame(0, $this->gapOf($client, 101)->attempts);

        // E a posição do cliente é a que a captura deixou: a volta atrás não
        // anda com ela em nenhum caminho, nem no sucesso, nem na recusa.
        $this->assertSame(100, $this->cursorOf($client)->last_nsu);
    }

    public function test_o_job_consulta_a_posicao_devida_e_nao_anda_com_o_cursor(): void
    {
        $account = Account::factory()->create();
        $client = $this->clientWithGap($account, 101);
        $this->cursor($client);

        $connector = $this->bindConnector();

        $this->runJob((int) $client->getKey(), FiscalSource::NfeDistribuicao);

        // O caminho do job que faz o trabalho existe pelo mesmo motivo do
        // cliente apagado: o comando só despacha, e quem descobre que o job
        // volta atrás é o job rodado. A posição pedida é a da lacuna, o falso
        // responde que não há documento naquela posição — uma tentativa contada
        // e adiada — e o cursor fica onde a captura o deixou.
        $this->assertSame([101], $connector->lookups);
        $this->assertSame(1, $this->gapOf($client, 101)->attempts);
        $this->assertTrue($this->gapOf($client, 101)->next_attempt_at->isFuture());
        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame(100, $this->cursorOf($client)->last_nsu);
    }

    public function test_o_job_reconcilia_o_cliente_dele_com_a_conta_corrente_residua_de_outra_conta(): void
    {
        $contaDoCliente = Account::factory()->create();
        $outraConta = Account::factory()->create();

        $client = $this->clientWithGap($contaDoCliente, 101);
        $this->cursor($client);

        // A mesma condição do teste do comando, agora do lado do worker: o job
        // anterior deixou a conta de outro cliente apontada aqui, e o job desta
        // noite é do cliente da primeira conta. Se a busca do job obedecer à
        // conta corrente, ele volta vazio e a lacuna deste cliente passa a noite
        // inteira sem ser tentada — sem erro, sem log, sem nada.
        resolve(CurrentTenant::class)->accountId = $outraConta->getKey();

        $connector = $this->bindConnector();

        $this->runJob((int) $client->getKey(), FiscalSource::NfeDistribuicao);

        $this->assertSame([101], $connector->lookups);
        $this->assertSame(1, $this->gapOf($client, 101)->attempts);

        // Durante a reconciliação a conta corrente é a do cliente: é isso que
        // torna coerente o resto do caminho — o cursor e as lacunas lidas — com o
        // cliente que este job está reconciliando.
        $this->assertSame([$contaDoCliente->getKey()], $connector->tenants);
    }

    public function test_o_job_devolve_a_conta_corrente_que_encontrou(): void
    {
        $contaDoCliente = Account::factory()->create();
        $outraConta = Account::factory()->create();

        $client = $this->clientWithGap($contaDoCliente, 101);
        $this->cursor($client);

        resolve(CurrentTenant::class)->accountId = $outraConta->getKey();

        $connector = $this->bindConnector();

        $this->runJob((int) $client->getKey(), FiscalSource::NfeDistribuicao);

        // O job fez o trabalho…
        $this->assertSame([101], $connector->lookups);

        // …e não deixou a conta do cliente para trás. O `queue:work` é longo e o
        // `CurrentTenant` é um singleton que ninguém zera entre jobs: deixar a
        // conta aqui faria o próximo job que não adota conta própria não achar o
        // cliente dele e encerrar em silêncio, todas as noites, até o worker
        // reiniciar. A captura é um desses jobs.
        $this->assertSame(
            $outraConta->getKey(),
            resolve(CurrentTenant::class)->accountId,
            'O job tem de devolver a conta corrente que encontrou, e não a do cliente.',
        );
    }

    public function test_cliente_apagado_encerra_o_job_em_silencio(): void
    {
        $account = Account::factory()->create();
        $client = $this->clientWithGap($account, 101);

        // Soft delete, como o apagar da carteira faz: o escopo de exclusão
        // lógica é o que não acha quem saiu, e reconciliar cliente apagado
        // gravaria documento de um cliente que a carteira não tem mais.
        $client->delete();

        $connector = $this->bindConnector();

        Log::spy();

        $this->runJob((int) $client->getKey(), FiscalSource::NfeDistribuicao);

        $this->assertSame([], $connector->lookups);
        $this->assertDatabaseCount('fiscal_cursors', 0);

        // Saiu da carteira, e a lacuna dele cai junto: é o caso previsto e não
        // é erro, então nada é logado. Quem precisa de aviso é o `null` do
        // outro tipo, e ele tem a própria linha.
        Log::shouldNotHaveReceived('warning');
    }

    public function test_job_de_cliente_que_ninguem_agora_tem_avisa_em_vez_de_sumir(): void
    {
        $orphan = 999_999;

        $connector = $this->bindConnector();

        Log::spy();

        $this->runJob($orphan, FiscalSource::NfeDistribuicao);

        $this->assertSame([], $connector->lookups);

        // Ninguém na carteira é dono daquele id. Não é a mesma coisa que cliente
        // apagado — que é o previsto e é silencioso — e é a única forma de uma
        // noite em que a reconciliação não fez nada não deixar rastro.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'fiscal.reconciliacao.cliente_ausente'
                && $context['client_id'] === $orphan
                && $context['source'] === FiscalSource::NfeDistribuicao->value);
    }

    public function test_o_job_uma_tentativa_so_e_o_timeout_abaixo_da_trava_e_do_worker(): void
    {
        $job = new ReconcileFiscalDocumentsJob(1, FiscalSource::NfeDistribuicao);

        // Uma tentativa só: recuperar é consulta ao CNPJ, e a reconciliação que
        // falhou precisa aparecer em vez de ser repetida às cegas.
        $this->assertSame(1, $job->tries);

        // Abaixo do TTL da trava: um job que pode ser morto pelo worker antes
        // de a trava vencer deixa a execução seguinte entrar enquanto a
        // antiga, lenta mas viva, ainda consulta — a consulta paralela que a
        // trava existe para impedir.
        $this->assertLessThan((int) config('fiscal.lock_ttl'), $job->timeout);

        // Abaixo do retry_after do redis: um job que encosta na janela é
        // exatamente o que o redis reentrega enquanto o original ainda corre.
        $this->assertLessThan($this->redisRetryAfter(), $job->timeout);

        // Abaixo do --timeout do worker, lido do entrypoint que o define.
        $this->assertLessThan($this->workerTimeout(), $job->timeout);
    }

    private function clientWithGap(
        Account $account,
        int $nsu,
        FiscalSource $source = FiscalSource::NfeDistribuicao,
    ): Client {
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalGap::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => $source,
            'nsu' => $nsu,
        ]);

        return $client;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function cursor(Client $client, array $attributes = []): FiscalCursor
    {
        $cursor = FiscalCursor::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'last_nsu' => 100,
        ]);

        $cursor->forceFill($attributes)->save();

        return $cursor;
    }

    private function cursorOf(Client $client): FiscalCursor
    {
        return FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->firstOrFail();
    }

    /**
     * A lacuna relida depois da execução, com o escopo de conta fora porque o
     * teste é sobre o que ficou gravado, e a conta corrente é uma condição que
     * o próprio teste controla.
     */
    private function gapOf(Client $client, int $nsu): FiscalGap
    {
        return FiscalGap::withoutGlobalScope('account')
            ->where('client_id', $client->getKey())
            ->where('nsu', $nsu)
            ->firstOrFail();
    }

    /**
     * O job rodado de verdade, com o conector falso ligado no container. É o
     * caminho que `Bus::fake()` esconde, e o que o job faz com o cliente que
     * recebeu ele é o que o comando não pode provar.
     */
    private function runJob(int $clientId, FiscalSource $source): void
    {
        (new ReconcileFiscalDocumentsJob($clientId, $source))
            ->handle($this->app->make(FiscalReconciliation::class));
    }

    /**
     * Um conector que registra as consultas por posição e responde sempre que
     * não há documento — a resposta do fisco que conta uma tentativa e adia a
     * próxima, e é o que torna a execução observável sem tocar a rede.
     *
     * Ele é registrado no `FiscalConnectorRegistry`, e não por uma ligação da
     * interface `FiscalConnector`: quem fala com o fisco resolve o conector pela
     * fonte, e o registro é a fonte dessa resolução.
     */
    private function bindConnector(FiscalSource $served = FiscalSource::NfeDistribuicao): FiscalConnector
    {
        $connector = new class($served) implements FiscalConnector
        {
            /**
             * As posições pedidas uma a uma: uma posição que ninguém pediu é um
             * buraco que ninguém fechou, e uma posição pedida a mais é consulta
             * indevida.
             *
             * @var list<int>
             */
            public array $lookups = [];

            /**
             * A conta corrente no momento de cada consulta. É a única forma de
             * observar a conta de **durante** a execução, já que o fim do job a
             * devolve ao valor que encontrou.
             *
             * @var list<?int>
             */
            public array $tenants = [];

            public function __construct(private readonly FiscalSource $served) {}

            public function source(): FiscalSource
            {
                return $this->served;
            }

            public function pull(Client $client, int $fromNsu, int $limit): PullResult
            {
                return new PullResult(
                    documents: [],
                    lastNsu: $fromNsu,
                    maxNsu: null,
                    more: false,
                    blockedUntil: null,
                    mayAdoptPosition: true,
                );
            }

            public function fetchByChave(Client $client, string $chave): ?PulledDocument
            {
                return null;
            }

            public function fetchByNsu(Client $client, int $nsu): ?PulledDocument
            {
                $this->lookups[] = $nsu;
                $this->tenants[] = resolve(CurrentTenant::class)->accountId;

                return null;
            }
        };

        $this->bindRegistry([$served->value => $connector]);

        return $connector;
    }

    /**
     * Um registro com exatamente estas fontes. Um registro sem a fonte pedida é a
     * situação de um job que entrou na fila antes de o conector da fonte existir:
     * a fonte é conhecida, o conector não.
     *
     * @param  array<string, class-string<FiscalConnector>|FiscalConnector>  $connectors
     */
    private function bindRegistry(array $connectors): void
    {
        $this->app->instance(FiscalConnectorRegistry::class, new FiscalConnectorRegistry($connectors));
    }

    private function redisRetryAfter(): int
    {
        return (int) config('queue.connections.redis.retry_after', 90);
    }

    /**
     * O --timeout do worker, lido do entrypoint que o define: a leitura é a
     * âncora do teste. Mudar o valor lá sem re-verificar as janelas daqui
     * precisa quebrar o teste — as duas pontas são o mesmo deploy.
     */
    private function workerTimeout(): int
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 3).'/docker/queue-entrypoint.sh');

        if ($entrypoint === false || ! preg_match('/--timeout=(\d+)/', $entrypoint, $matches)) {
            $this->fail('--timeout do worker não encontrado em docker/queue-entrypoint.sh.');
        }

        return (int) $matches[1];
    }
}
