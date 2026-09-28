<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalSource;
use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O comando `fiscal:capture`: quem vira a carteira em fila. Um job por
 * cliente e por fonte — nunca um job por carteira, porque um lote com mTLS
 * não cabe na janela do worker — e nenhum job para uma fonte sem conector
 * nesta versão.
 *
 * A agenda de CT-e também mora aqui, porque é a continuação desta mesma
 * história: o comando despacha, e rodá-lo sozinho, de hora em hora, é uma
 * decisão de instalação que começa desligada (`fiscal.cte_scheduled`).
 *
 * `Bus::fake()` de propósito: o que se verifica é o despacho, nunca a fila.
 * Rodar o job aqui seria testar de novo o que o `FiscalCaptureServiceTest`
 * já cobre.
 */
class CaptureFiscalDocumentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O disco do certificado, não o do XML: é a factory `withPassword()`
        // quem escreve no disco, e um cliente capturável de teste precisa dela.
        Storage::fake('certificates');
        Bus::fake();
    }

    public function test_dispatches_one_job_per_capturable_client(): void
    {
        $account = Account::factory()->create();

        $first = $this->capturableClient($account);
        $this->capturableClient($account);
        $this->capturableClient($account);

        $this->artisan('fiscal:capture')
            ->expectsOutputToContain('Capturas despachadas: 3')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(CaptureFiscalDocumentsJob::class, 3);
        Bus::assertDispatched(
            CaptureFiscalDocumentsJob::class,
            fn (CaptureFiscalDocumentsJob $job): bool => $job->clientId === $first->getKey()
                && $job->source === FiscalSource::NfeDistribuicao,
        );
    }

    public function test_does_not_dispatch_for_a_client_without_a_usable_certificate(): void
    {
        $account = Account::factory()->create();

        // Sem certificado nenhum: o upload nunca aconteceu.
        Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        // Certificado atual sem senha guardada: o estado que pede reenvio.
        $noPassword = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $noPassword->getKey(),
        ]);

        // Certificado com senha, vencido ontem: a validade é hoje que conta.
        $expired = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $expired->getKey(),
            'valid_until' => now()->subDay(),
        ]);

        // Certificado substituído: o atual é quem manda, e este cliente não
        // tem um — o escopo tem de enxergar a substituição, não só a ausência.
        $replaced = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $replaced->getKey(),
            'replaced_at' => now(),
        ]);

        $this->artisan('fiscal:capture')->assertSuccessful();

        Bus::assertNotDispatched(CaptureFiscalDocumentsJob::class);
    }

    public function test_dispatches_only_for_the_requested_client(): void
    {
        $account = Account::factory()->create();

        $wanted = $this->capturableClient($account);
        $this->capturableClient($account);

        $this->artisan('fiscal:capture', ['--client' => (string) $wanted->getKey()])
            ->expectsOutputToContain('Capturas despachadas: 1')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(CaptureFiscalDocumentsJob::class, 1);
        Bus::assertDispatched(
            CaptureFiscalDocumentsJob::class,
            fn (CaptureFiscalDocumentsJob $job): bool => $job->clientId === $wanted->getKey(),
        );
    }

    /**
     * CT-e pelo comando é CT-e pelo mesmo caminho do NF-e: uma fonte na linha de
     * comando, um job por cliente capturável, nenhuma fonte proibida aqui.
     *
     * O comando **não** consulta `fiscal.cte_enabled` — a chave é do botão da
     * tela, e o comando é o caminho do canário, que precisa rodar antes de a
     * agenda existir. A afirmação logo abaixo é o que fixa isso: se um dia
     * alguém colocar a chave do botão no comando, este teste quebra.
     */
    public function test_despacha_um_job_de_cte_para_o_cliente_pedido(): void
    {
        $account = Account::factory()->create();

        $wanted = $this->capturableClient($account);
        $this->capturableClient($account);

        $this->assertFalse(config('fiscal.cte_enabled'), 'A captura de CT-e pela tela precisa nascer desligada.');

        $this->artisan('fiscal:capture', [
            '--source' => 'cte_distribuicao',
            '--client' => (string) $wanted->getKey(),
        ])
            ->expectsOutputToContain('Capturas despachadas: 1')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(CaptureFiscalDocumentsJob::class, 1);
        Bus::assertDispatched(
            CaptureFiscalDocumentsJob::class,
            fn (CaptureFiscalDocumentsJob $job): bool => $job->clientId === $wanted->getKey()
                && $job->source === FiscalSource::CteDistribuicao,
        );
    }

    /**
     * A recusa de uma fonte que o registro não serve continua de pé, e ela é do
     * registro e não da fonte: as duas têm conector nesta versão, então a
     * recusa é exercitada com um registro sem CT-e — a situação de um comando
     * numa versão em que o conector daquela fonte não existe. O que mudou desde
     * que esta recusa nasceu é que CT-e deixou de ser a fonte proibida: hoje ela
     * é uma fonte servida, e quem é proibida é a fonte que ninguém cadastrou.
     */
    public function test_rejects_a_source_no_connector_serves(): void
    {
        $this->app->instance(FiscalConnectorRegistry::class, new FiscalConnectorRegistry([
            FiscalSource::NfeDistribuicao->value => NfeDistributionConnector::class,
        ]));

        $this->artisan('fiscal:capture', ['--source' => 'cte_distribuicao'])
            ->expectsOutputToContain('não tem conector')
            ->assertFailed();

        Bus::assertNotDispatched(CaptureFiscalDocumentsJob::class);
    }

    public function test_rejects_an_unknown_source(): void
    {
        $this->artisan('fiscal:capture', ['--source' => 'nao_existe'])
            ->expectsOutputToContain('Fonte inválida')
            ->assertFailed();

        Bus::assertNotDispatched(CaptureFiscalDocumentsJob::class);
    }

    public function test_job_timeout_stays_below_the_worker_timeout(): void
    {
        $job = new CaptureFiscalDocumentsJob(1, FiscalSource::NfeDistribuicao);

        // Abaixo do retry_after do redis: um job que encosta na janela é
        // exatamente o que o redis reentrega enquanto o original ainda corre.
        $this->assertLessThan($this->redisRetryAfter(), $job->timeout);

        // Abaixo do --timeout do worker, lido do entrypoint que o define.
        $this->assertLessThan($this->workerTimeout(), $job->timeout);
    }

    public function test_a_job_for_a_client_deleted_between_dispatch_and_run_is_a_noop(): void
    {
        // Uma requisição que escapasse quebraria o teste aqui — e é a forma
        // honesta de verificar um no-op: nada acontece, nenhuma rede envolvida.
        Http::preventStrayRequests();

        $account = Account::factory()->create();
        $client = $this->capturableClient($account);

        // Soft delete, como o apagar da carteira faz: o escopo de exclusão
        // lógica é o que não acha quem saiu — capturar cliente apagado seria
        // pior do que não capturar.
        $client->delete();

        (new CaptureFiscalDocumentsJob((int) $client->getKey(), FiscalSource::NfeDistribuicao))
            ->handle($this->app->make(FiscalCaptureService::class));

        // Nenhuma consulta de saída, nenhum cursor: o job de um cliente que
        // saiu da carteira entre o despacho e a execução apenas termina.
        $this->assertDatabaseCount('fiscal_cursors', 0);
    }

    public function test_schedule_registers_the_capture_hourly_without_overlap(): void
    {
        $events = $this->captureEvents();

        $this->assertNotEmpty($events, 'fiscal:capture deve estar agendado.');
        $this->assertTrue(
            $events->every(fn (Event $event): bool => $event->getExpression() === '0 * * * *'),
            'fiscal:capture deve rodar de hora em hora.',
        );
        $this->assertTrue(
            $events->every(fn (Event $event): bool => $event->withoutOverlapping),
            'fiscal:capture deve proibir sobreposição da própria entrada.',
        );
    }

    /**
     * A agenda de CT-e nasce desligada, e a pergunta que este teste faz é se ela
     * **está agendada**, não se a chave está falsa: uma chave errada lida pelo
     * contrário registraria a entrada mesmo assim, e é isso que transformaria uma
     * instalação nova em tráfego de hora em hora contra um serviço que ninguém
     * verificou.
     *
     * O que fica de pé de qualquer jeito é a entrada de NF-e: a agenda de CT-e
     * não pode custar nada ao caminho de produção.
     */
    public function test_a_entrada_de_cte_nao_existe_antes_do_canario(): void
    {
        $this->assertFalse(config('fiscal.cte_scheduled'), 'A agenda de CT-e precisa nascer desligada.');

        $this->assertCount(
            0,
            $this->captureEventsFor(FiscalSource::CteDistribuicao->value),
            'Nenhuma captura de CT-e pode estar agendada antes do canário de um cliente. Entradas de captura: '
                .implode(' | ', $this->captureCommands()),
        );

        $nfe = $this->defaultCaptureEvent();

        $this->assertNotNull($nfe, 'A captura de NF-e precisa continuar agendada.');
        $this->assertSame('0 * * * *', $nfe->getExpression(), 'A captura de NF-e continua de hora em hora.');
        $this->assertTrue($nfe->withoutOverlapping, 'A proteção de sobreposição da entrada de NF-e continua.');
    }

    /**
     * Com a chave ligada, a entrada de CT-e é uma entrada **própria** — a mesma
     * janela e a mesma proteção da de NF-e, e não uma fusão das duas: um
     * `--source` que se perdesse faria a entrada de CT-e capturar NF-e em
     * duplicidade, com o dobro de consulta no orçamento que o fisco conta por
     * hora. E a de NF-e continua registrada ao lado dela.
     *
     * O comando da entrada é conferido inteiro, porque a ausência de `--client`
     * é a afirmação mais consequente que este arquivo faz: sem ele a agenda
     * alcança todo cliente capturável de todas as contas, e um `--client` aqui
     * seria um canário de carteira que ninguém autorizou.
     *
     * A agenda é montada no boot da aplicação, então mexer na chave depois do
     * boot não registraria nada e este teste passaria sem exercitar o registro.
     * Por isso a aplicação nasce de novo com a chave no ambiente — que é
     * exatamente como a instalação a recebe, e o que impede que o teste passe por
     * causa de uma configuração que ninguém chegou a exercitar.
     */
    public function test_a_entrada_de_cte_aparece_com_a_chave_da_agenda_ligada(): void
    {
        $_SERVER['FISCAL_CTE_SCHEDULED'] = 'true';

        try {
            $this->refreshApplication();

            $this->assertTrue(config('fiscal.cte_scheduled'), 'A chave da agenda precisa ter chegado do ambiente.');

            $cte = $this->captureEventsFor(FiscalSource::CteDistribuicao->value);

            $this->assertCount(1, $cte, 'A agenda de CT-e é uma entrada só, do tipo da de NF-e.');

            // O comando inteiro da entrada, e não só a parte que o filtro
            // procurava: um `--client` acrescentado aqui passaria por toda a
            // verificação acima e transformaria a agenda num canário de um
            // cliente só — que é a decisão que o gate de liberação não
            // autoriza. Hoje a entrada cobre a carteira inteira, e é isso que
            // o comentário da agenda diz em voz alta.
            $this->assertSame(
                Application::formatCommandString('fiscal:capture --source=cte_distribuicao'),
                (string) $cte[0]->command,
                'A entrada de CT-e é exatamente este comando: sem --client ela alcança todo cliente capturável de todas as contas.',
            );

            $this->assertSame('0 * * * *', $cte[0]->getExpression(), 'A captura de CT-e roda de hora em hora.');
            $this->assertTrue($cte[0]->withoutOverlapping, 'A captura de CT-e não pode se sobrepor a si mesma.');

            $nfe = $this->defaultCaptureEvent();

            $this->assertNotNull($nfe, 'Ligar a agenda de CT-e não pode tirar a entrada de NF-e.');
            $this->assertSame('0 * * * *', $nfe->getExpression(), 'A entrada de NF-e continua de hora em hora.');
            $this->assertTrue($nfe->withoutOverlapping, 'A proteção de sobreposição da entrada de NF-e continua.');
        } finally {
            unset($_SERVER['FISCAL_CTE_SCHEDULED']);
        }
    }

    private function capturableClient(Account $account): Client
    {
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return $client;
    }

    /**
     * As entradas de `fiscal:capture` que a agenda registrou — e não o que a
     * configuração diz que deveria estar lá: uma entrada que o registro apagasse,
     * ou uma chave ligada que ninguém chegou a exercitar, não apareceriam aqui.
     *
     * @return Collection<int, Event>
     */
    private function captureEvents(): Collection
    {
        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'fiscal:capture'))
            ->values();
    }

    /**
     * As mesmas entradas como texto de comando, que é o que uma falha precisa
     * mostrar: o dump de um `Event` arrasta o container inteiro atrás dele.
     *
     * @return array<int, string>
     */
    private function captureCommands(): array
    {
        return $this->captureEvents()
            ->map(fn (Event $event): string => (string) $event->command)
            ->all();
    }

    /**
     * A entrada que roda a captura de uma fonte nomeada na linha de comando. A
     * de NF-e não é encontrada aqui: ela não passa `--source`, porque a fonte
     * padrão do comando já é a dela.
     *
     * @return array<int, Event>
     */
    private function captureEventsFor(string $source): array
    {
        return $this->captureEvents()
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, "--source={$source}"))
            ->values()
            ->all();
    }

    /**
     * A entrada que roda a captura com a fonte padrão do comando — a de NF-e, e
     * a única que a agenda registra sem `--source`.
     */
    private function defaultCaptureEvent(): ?Event
    {
        return $this->captureEvents()
            ->first(fn (Event $event): bool => ! str_contains((string) $event->command, '--source='));
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
