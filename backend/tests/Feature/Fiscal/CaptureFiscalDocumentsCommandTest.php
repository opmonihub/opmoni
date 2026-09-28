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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_rejects_a_source_no_connector_serves(): void
    {
        // As duas fontes têm conector nesta versão, então a recusa é exercitada
        // com um registro que não conhece a fonte de CT-e — que é a situação de
        // um comando numa versão em que o conector daquela fonte não existe.
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
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'fiscal:capture'));

        $this->assertNotEmpty($events, 'fiscal:capture deve estar agendado.');
        $this->assertTrue(
            $events->every(fn ($event): bool => $event->getExpression() === '0 * * * *'),
            'fiscal:capture deve rodar de hora em hora.',
        );
        $this->assertTrue(
            $events->every(fn ($event): bool => $event->withoutOverlapping),
            'fiscal:capture deve proibir sobreposição da própria entrada.',
        );
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
