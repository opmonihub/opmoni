<?php

namespace Tests\Integration;

use App\Services\SerproCredentialSnapshot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

abstract class PlatformProcessTestCase extends TestCase
{
    /** @var array<int, array{process: Process, base: string}> */
    private array $workers = [];

    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        MemberAccessConcurrencyTest::assertIsolatedEnvironment();
        Http::preventStrayRequests();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->directory = sys_get_temp_dir().'/opmoni-platform-race-'.bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker['process']->stop();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    protected function worker(string $action, array $input = []): Process
    {
        $base = $this->directory.'/worker-'.count($this->workers);
        $process = new Process([PHP_BINARY, 'tests/Integration/platform-process-worker.php'], base_path(), ['FISCAL_ENVIRONMENT' => 'producao']);
        $process->setInput(json_encode($input + ['action' => $action, 'base' => $base], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $this->workers[spl_object_id($process)] = ['process' => $process, 'base' => $base];
        $process->start();
        $processId = $process->getPid();
        $this->waitFor($process, 'ready');
        $identity = $this->signalData($process, 'ready');
        fwrite(STDERR, json_encode(['worker' => $action, ...$identity], JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertSame($processId, $identity['pid']);
        $this->assertNotSame((int) DB::selectOne('select pg_backend_pid() as pid')->pid, $identity['database_pid']);
        foreach ($this->workers as $other) {
            if ($other['process'] !== $process && $other['process']->isRunning()) {
                $previous = json_decode(file_get_contents($other['base'].'.ready'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertNotSame($previous['pid'], $identity['pid']);
                $this->assertNotSame($previous['database_pid'], $identity['database_pid']);
            }
        }

        return $process;
    }

    protected function signal(Process $worker, string $suffix): string
    {
        return $this->workers[spl_object_id($worker)]['base'].'.'.$suffix;
    }

    protected function signalData(Process $worker, string $suffix): array
    {
        return json_decode(file_get_contents($this->signal($worker, $suffix)), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function waitFor(Process $worker, string $suffix): void
    {
        $deadline = microtime(true) + 12;
        while (! file_exists($this->signal($worker, $suffix))) {
            if (! $worker->isRunning() && ! file_exists($this->signal($worker, $suffix))) {
                $this->fail('Worker exited before '.$suffix.'; exit='.$worker->getExitCode().'; output bytes='.strlen($worker->getOutput()).'; stderr bytes='.strlen($worker->getErrorOutput()));
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Worker barrier timed out: '.$suffix);
            }
            usleep(10000);
        }
        $this->assertFileExists($this->signal($worker, $suffix));
    }

    protected function release(Process $worker, string $suffix = 'release'): void
    {
        touch($this->signal($worker, $suffix));
    }

    protected function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), 'Worker exit='.$worker->getExitCode().'; stderr bytes='.strlen($worker->getErrorOutput()));
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('unexpected_exception', $result, 'Unexpected worker exception: '.($result['unexpected_exception'] ?? 'none'));

        return $result;
    }

    protected function assertWaiting(Process $worker, string $sql): void
    {
        $identity = $this->signalData($worker, 'ready');
        $deadline = microtime(true) + 4;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$identity['database_pid']]);
            if ($activity?->wait_event_type === 'Lock') {
                $this->assertStringContainsString($sql, $activity->query);
                if ($sql === 'accounts') {
                    $this->assertStringContainsString('for update', $activity->query);
                }
                fwrite(STDERR, json_encode(['barrier' => $sql, 'pid' => $identity['pid'], 'database_pid' => $identity['database_pid'], 'wait' => 'Lock']).PHP_EOL);

                return;
            }
            $this->assertTrue($worker->isRunning(), 'Contender exited before database wait.');
            $this->assertLessThan($deadline, microtime(true), 'No real PostgreSQL lock wait observed.');
            usleep(10000);
        } while (true);
    }

    /** @return array{path: string, password: string, document: string, digest: string} */
    protected function certificate(string $label, string $document): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['CN' => 'Platform:'.$document], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        $password = bin2hex(random_bytes(12));
        $this->assertTrue(openssl_pkcs12_export($certificate, $bytes, $key, $password));
        $path = $this->directory.'/'.$label.'.p12';
        file_put_contents($path, $bytes);
        chmod($path, 0600);

        return ['path' => $path, 'password' => $password, 'document' => $document, 'digest' => hash('sha256', $bytes)];
    }

    protected function upload(array $certificate): UploadedFile
    {
        return new UploadedFile($certificate['path'], 'contracting.p12', 'application/octet-stream', null, true);
    }

    /** Compare secrets locally; only boolean comparisons and public metadata leave workers. */
    public static function tuple(?SerproCredentialSnapshot $snapshot, array $expected): array
    {
        if ($snapshot === null) {
            return ['configured' => false];
        }

        return $snapshot->jsonSerialize() + [
            'generation' => $snapshot->generation(),
            'key_matches' => $snapshot->consumerKey() === $expected['key'],
            'secret_matches' => $snapshot->consumerSecret() === $expected['secret'],
            'certificate_matches' => hash('sha256', $snapshot->certificateBytes() ?? '') === $expected['certificate']['digest'],
            'password_matches' => $snapshot->certificatePassword() === $expected['certificate']['password'],
            'usable' => $snapshot->hasUsableCertificate(),
        ];
    }

    protected function assertCompleteTuple(array $tuple, array $certificate): void
    {
        foreach (['key_matches', 'secret_matches', 'certificate_matches', 'password_matches', 'usable'] as $field) {
            $this->assertTrue($tuple[$field], $field);
        }
        $this->assertSame($certificate['document'], $tuple['document']);
    }
}
