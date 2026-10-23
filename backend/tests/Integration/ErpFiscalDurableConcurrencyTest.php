<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalCertificateRevisionService;
use App\Erp\Fiscal\ErpFiscalConfigurationRevisionService;
use App\Erp\Fiscal\ErpFiscalNumberAllocator;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Feature\ErpFiscalModelBuilderSchemaTest;
use Tests\TestCase;

class ErpFiscalDurableConcurrencyTest extends TestCase
{
    private array $baseline = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task17-20261021', getenv('ERP_FISCAL_DURABLE_ISOLATED'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('opmoni_test', DB::selectOne('select current_database() as name')->name);
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $expected) {
            $this->assertSame($expected, (int) config('database.redis.'.$connection.'.database'));
        }
        Redis::connection('default')->client()->ping();
        $this->assertTrue(Schema::hasTable('erp_fiscal_attempts'), 'Root must review and apply the additive Task17 migration before running this test.');

        $this->assertSame('opmoni-access-test-', config('database.redis.options.prefix'));
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $expected) {
            $this->assertSame($expected, (int) Redis::connection($connection)->client()->getDBNum());
        }
        foreach (['erp_companies', 'erp_establishments', 'erp_fiscal_documents', 'erp_fiscal_events', 'erp_fiscal_invalidations', 'erp_fiscal_certificates', 'erp_fiscal_certificate_revisions', 'erp_fiscal_configurations', 'erp_fiscal_configuration_revisions', 'erp_fiscal_attempts', 'erp_fiscal_number_sequences', 'erp_operation_receipts', 'erp_accounting_deliveries', 'erp_accounting_delivery_dispatches'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->baseline[$table] = ['ids' => $rows->pluck('id')->all(), 'hash' => hash('sha256', $rows->toJson())];
        }
        config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true, 'erp.release_mode' => 'production']);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->baseline as $table => $baseline) {
                $this->assertSame($baseline['hash'], hash('sha256', DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get()->toJson()), 'Pre-existing rows changed in '.$table);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_independent_postgres_workers_allocate_unique_monotonic_numbers_in_one_company_scope(): void
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj()]);
        $actor = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);

        $workerCount = 4;
        $barrier = tempnam(sys_get_temp_dir(), 'task17-fiscal-allocation-');
        $this->assertNotFalse($barrier);
        unlink($barrier);
        $workers = [];
        try {
            for ($i = 0; $i < $workerCount; $i++) {
                $worker = new Process([PHP_BINARY, __DIR__.'/ErpFiscalDurableAttemptConcurrencyWorker.php']);
                $worker->setInput(json_encode([
                    'operation' => 'allocate',
                    'company_id' => $company->id,
                    'model' => '55',
                    'environment' => 'homologation',
                    'series' => 1,
                    'initial_next_number' => 1,
                    'barrier' => $barrier,
                ], JSON_THROW_ON_ERROR));
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }
            file_put_contents($barrier, 'go');

            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            $numbers = array_column($results, 'number');
            sort($numbers);
            $this->assertSame([1, 2, 3, 4], $numbers);
            $this->assertCount($workerCount, array_unique(array_column($results, 'backend_pid')));
            $this->assertSame(5, (int) DB::table('erp_fiscal_number_sequences')
                ->where('erp_company_id', $company->id)
                ->where('model', '55')
                ->where('environment', 'homologation')
                ->where('series', 1)
                ->value('next_number'));
            $this->assertSame(0, ErpFiscalDocument::query()->where('erp_company_id', $company->id)->count(), 'Allocation must not create or alter document rows.');
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            @unlink($barrier);
        }
    }

    public function test_independent_workers_claim_once_and_expired_token_cannot_publish(): void
    {
        $fixture = $this->fiscalFixture();
        $barrier = $this->path('claim-go');
        $workers = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $workers[] = $this->worker(['operation' => 'claim', 'attempt_id' => $fixture['attempt']->id, 'barrier' => $barrier]);
            }
            file_put_contents($barrier, 'go');
            $results = array_map(fn (Process $worker): array => $this->workerResult($worker), $workers);
            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['claimed'])));
            $this->assertCount(3, array_unique(array_column($results, 'backend_pid')));
            $winner = array_values(array_filter($results, fn (array $result): bool => $result['claimed']))[0];
            $this->assertSame(0, $winner['transaction_level']);
            $attempt = $fixture['attempt']->fresh();
            $this->assertSame(1, $attempt->dispatch_attempts);
            $attempt->update(['lease_expires_at' => now()->subSecond()]);
            $reclaimer = $this->worker(['operation' => 'claim', 'attempt_id' => $attempt->id, 'barrier' => $barrier]);
            $workers[] = $reclaimer;
            $newClaim = $this->workerResult($reclaimer);
            $this->assertTrue($newClaim['claimed']);
            $this->assertNotSame($winner['token'], $newClaim['token']);
            $published = false;
            $this->assertFalse(app(ErpFiscalAttemptService::class)->finalize($attempt->id, $winner['token'], 'accepted', [], null, null, function () use (&$published): void {
                $published = true;
            }));
            $this->assertFalse($published);
            $this->assertSame($newClaim['token'], $attempt->fresh()->lease_token);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            @unlink($barrier);
        }
    }

    public function test_persistent_outbox_survives_push_failure_and_provider_releases_company_locks_with_pinned_rotation(): void
    {
        $fixture = $this->fiscalFixture();
        $attempt = $fixture['attempt'];
        $service = app(ErpFiscalAttemptService::class);
        $originalDispatcher = app(Dispatcher::class);
        $failing = \Mockery::mock(Dispatcher::class);
        $failing->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('synthetic queue push outage'));
        $this->app->instance(Dispatcher::class, $failing);
        DB::transaction(fn () => $service->dispatchAfterCommit($attempt));
        $this->assertSame('new', $attempt->fresh()->outbox_state);
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->app->instance(Dispatcher::class, $originalDispatcher);
        $queue = 'erp-fiscal-task17-'.bin2hex(random_bytes(8));
        config(['queue.default' => 'database', 'queue.connections.database.queue' => $queue]);
        $service->dispatchAfterCommit($attempt);
        $this->assertSame(1, DB::table('jobs')->where('queue', $queue)->count());
        $barrier = $this->path('provider-go');
        $ready = $this->path('provider-ready');
        $release = $this->path('provider-release');
        file_put_contents($barrier, 'go');
        $worker = $this->worker(['operation' => 'work', 'attempt_id' => $attempt->id, 'queue' => $queue, 'barrier' => $barrier, 'provider_ready' => $ready, 'provider_release' => $release]);
        try {
            $this->waitFor($ready, $worker);
            $observed = json_decode(file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $observed['transaction_level']);
            $this->assertSame($attempt->erp_fiscal_certificate_revision_id, $observed['certificate_revision_id']);
            $this->assertSame($attempt->erp_fiscal_configuration_revision_id, $observed['configuration_revision_id']);
            $this->assertSame($fixture['issuer']->name, $observed['issuer_name']);
            DB::transaction(function () use ($fixture): void {
                DB::statement("SET LOCAL lock_timeout TO '1000ms'");
                $company = ErpCompany::query()->whereKey($fixture['company']->id)->lockForUpdate()->firstOrFail();
                $company->update(['operating_settings' => ['mutation_while_provider_waits' => true]]);
                $fixture['issuer']->update(['name' => 'Changed after frozen signing']);
                app(ErpFiscalCertificateRevisionService::class)->rotate($fixture['certificate'], $this->pkcs12($fixture['issuer']->cnpj), '', $fixture['actor']->id);
            });
            $this->assertNotSame($attempt->erp_fiscal_certificate_revision_id, $fixture['certificate']->fresh()->current_revision_id);
            file_put_contents($release, 'go');
            $result = $this->workerResult($worker);
            $this->assertSame(0, $result['transaction_level']);
            $this->assertSame('rejected', $result['status']);
            $this->assertSame(1, $result['dispatch_attempts']);
            $this->assertSame('rejected', $fixture['document']->fresh()->status);
            $this->assertSame(0, DB::table('jobs')->where('queue', $queue)->count());
            $this->assertSame($attempt->erp_fiscal_certificate_revision_id, $attempt->fresh()->erp_fiscal_certificate_revision_id);
            $frozen = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt->fresh());
            $this->assertSame($observed['issuer_name'], $frozen['request']['source']['issuer']['legal_name']);
        } finally {
            file_put_contents($release, 'go');
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            @unlink($barrier);
            @unlink($ready);
            @unlink($release);
        }
    }

    private function fiscalFixture(): array
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj()]);
        $actor = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $issuer = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $company->cnpj]);
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id]);
        $revision = app(ErpFiscalConfigurationRevisionService::class)->replace($issuer, ['environment' => 'homologation',
            'issuer' => ['tax_regime' => 3, 'state_registration' => '110042490114', 'uf' => 'SP', 'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city_name' => 'Sao Paulo', 'zip_code' => '01001000']],
            'models' => ['55' => ['series' => 1, 'next_number' => 1, 'operation_nature_id' => $nature->id]]], $actor->id);
        $certificate = app(ErpFiscalCertificateRevisionService::class)->store($issuer, 'Isolated retained issuer', $this->pkcs12($issuer->cnpj), '', $actor->id);
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $source['issuer']['cnpj'] = $issuer->cnpj;
        $source['issuer']['legal_name'] = $issuer->name;
        $source['identity']['number'] = app(ErpFiscalNumberAllocator::class)->reserveExplicitNumber($company->id, '55', 'homologation', 1, 1);
        $source['identity']['access_key'] = app(ErpFiscalPayloadBuilder::class)->buildAccessKey(['uf' => 'SP', 'cnpj' => $issuer->cnpj, 'model' => '55', 'series' => 1, 'number' => 1, 'cnf' => '12345678', 'issued_at' => $source['identity']['issued_at']]);
        $document = ErpFiscalDocument::query()->create(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'erp_fiscal_certificate_id' => $certificate->id,
            'certificate_revision' => 1, 'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 1, 'access_key' => $source['identity']['access_key'], 'status' => 'queued', 'idempotency_key' => 'retained-'.bin2hex(random_bytes(8))]);
        $attempt = app(ErpFiscalAttemptService::class)->createIntent(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'erp_fiscal_document_id' => $document->id,
            'action' => 'document:transmit', 'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 1, 'actor_user_id' => $actor->id, 'idempotency_key' => $document->idempotency_key,
            'erp_fiscal_configuration_revision_id' => $revision->id, 'erp_fiscal_certificate_revision_id' => $certificate->current_revision_id, 'schema_release' => app(ErpFiscalSchemaValidator::class)->entrypoint('55', 'request'),
            'request' => ['source' => $source, 'authorization' => ['access_kind' => 'direct']]]);

        return compact('company', 'actor', 'issuer', 'revision', 'certificate', 'document', 'attempt');
    }

    private function pkcs12(string $cnpj): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => $cnpj], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        $bytes = '';
        $this->assertTrue(openssl_pkcs12_export($cert, $bytes, $key, ''));

        return $bytes;
    }

    private function path(string $suffix): string
    {
        return sys_get_temp_dir().'/task17-fiscal-'.bin2hex(random_bytes(8)).'-'.$suffix;
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/ErpFiscalDurableAttemptConcurrencyWorker.php']);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(25);
        $process->start();

        return $process;
    }

    private function waitFor(string $path, Process $process): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path, $process->getErrorOutput().$process->getOutput());
    }

    private function workerResult(Process $process): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function uniqueCnpj(): string
    {
        $base = (string) random_int(100000000000, 999999999999);
        $digits = array_map('intval', str_split($base));
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $position => $weights) {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += $digits[$index] * $weight;
            }
            $remainder = $sum % 11;
            $digits[] = $remainder < 2 ? 0 : 11 - $remainder;
        }

        return implode('', $digits);
    }
}
