<?php

namespace Tests\Integration;

use App\Models\Account;
use App\Models\AccountContractingCertificate;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\AccountCertificateVault;
use App\Services\AccountContractingCertificateVault;
use App\Services\SerproConnectionManager;
use App\Services\SerproCredentialLifecycle;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

class PlatformCredentialConcurrencyTest extends PlatformProcessTestCase
{
    private array $oldCertificate;

    private array $newCertificate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldCertificate = $this->certificate('old', '12345678000195');
        $this->newCertificate = $this->certificate('new', '11222333000181');
        $this->admin = User::factory()->create(['id' => random_int(100000, 2000000000), 'is_super_admin' => true]);
    }

    public function test_competing_initial_http_writes_create_one_complete_versioned_singleton(): void
    {
        $first = $this->worker('save-http', $this->saveInput('old') + ['actor' => $this->admin->id, 'pause' => 'advisory']);
        $this->waitFor($first, 'held');
        $second = $this->worker('save-http', $this->saveInput('new') + ['actor' => $this->admin->id]);
        $this->assertWaiting($second, 'pg_advisory_xact_lock');
        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);
        $this->assertSame(200, $firstResult['status']);
        $this->assertSame(200, $secondResult['status']);
        $this->assertTrue($firstResult['no_store']);
        $this->assertTrue($secondResult['no_store']);
        $connection = SerproConnection::sole();
        $this->assertSame(2, $connection->revision);
        $this->assertSame([['id' => $connection->id, 'revision' => 1]], $firstResult['connection_writes']);
        $this->assertSame([['id' => $connection->id, 'revision' => 2]], $secondResult['connection_writes']);
        $this->assertCompleteTuple($this->currentTuple('new'), $this->newCertificate);
        $this->assertNull($connection->contracting_account_id);
        $this->assertNull($connection->account_contracting_certificate_id);
    }

    public static function mutationOrders(): array
    {
        return ['rotation first' => [true], 'source first' => [false]];
    }

    #[DataProvider('mutationOrders')]
    public function test_rotation_and_linked_source_replacement_compose_in_either_lock_order(bool $rotationFirst): void
    {
        $account = $this->linkedConnection();
        $source = AccountContractingCertificate::sole();
        $rotation = ['key' => 'new-key', 'secret' => 'new-secret', 'source' => 'account', 'account' => $account->id];
        $replacement = ['account' => $account->id, 'source_id' => $source->id, 'certificate' => $this->newCertificate];
        $first = $this->worker($rotationFirst ? 'save' : 'replace', ($rotationFirst ? $rotation : $replacement) + ['pause' => $rotationFirst ? 'connection' : 'source-update']);
        $this->waitFor($first, 'held');
        $second = $this->worker($rotationFirst ? 'replace' : 'save', $rotationFirst ? $replacement : $rotation);
        $this->assertWaiting($second, 'accounts');
        $this->release($first);
        $this->assertSame(200, $this->workerResult($first)['status']);
        $this->assertSame(200, $this->workerResult($second)['status']);
        $this->assertCompleteTuple($this->currentTuple('new'), $this->newCertificate);
        $connection = SerproConnection::sole();
        $this->assertSame(3, $connection->revision);
        $this->assertSame(2, $source->fresh()->revision);
        $this->assertSame($source->id, $connection->account_contracting_certificate_id);
        $this->assertSame($source->fresh()->document, $connection->contratante_numero);
        $this->assertNull($connection->certificate_encrypted);
        $this->assertNull($connection->certificate_password_encrypted);
    }

    public static function sourceSwitches(): array
    {
        return ['upload to account then upload retry' => ['upload', false], 'account to upload then account' => ['account', false], 'higher to lower source discovery retry' => ['between', false], 'lower to higher source discovery retry' => ['between', true]];
    }

    #[DataProvider('sourceSwitches')]
    public function test_source_switches_recheck_discovery_and_preserve_complete_source_state(string $scenario, bool $reverse): void
    {
        $first = Account::factory()->create();
        $second = Account::factory()->create();
        foreach ([$first, $second] as $account) {
            app(AccountContractingCertificateVault::class)->replace($account, $this->upload($this->oldCertificate), $this->oldCertificate['password']);
        }
        [$oldAccount, $newAccount] = $reverse ? [$first, $second] : [$second, $first];
        if ($scenario === 'upload') {
            $this->uploadConnection();
        } else {
            app(SerproConnectionManager::class)->save('old-key', 'old-secret', null, null, false, 'account', $oldAccount->id);
        }
        $before = app(SerproCredentialLifecycle::class)->snapshot();
        $contenderInput = $scenario === 'account'
            ? ['key' => 'new-key', 'secret' => 'new-secret', 'source' => 'account', 'account' => $second->id]
            : $this->saveInput('new');
        $contender = $this->worker('save', $contenderInput + ['pause' => 'discovery']);
        $this->waitFor($contender, 'held');
        $this->assertSame(0, $this->signalData($contender, 'held')['transaction_level']);
        $changerInput = $scenario === 'account' ? $this->saveInput('old') : ['key' => 'old-key', 'secret' => 'old-secret', 'source' => 'account', 'account' => $scenario === 'upload' ? $second->id : $newAccount->id];
        $this->assertSame(200, $this->workerResult($this->worker('save', $changerInput))['status']);
        $this->release($contender);
        $result = $this->workerResult($contender);
        $this->assertSame(200, $result['status']);
        $connection = SerproConnection::sole();
        $this->assertSame(3, $connection->revision);
        $this->assertFalse(app(SerproCredentialLifecycle::class)->isCurrent($before));
        if ($scenario === 'account') {
            $this->assertSame($second->id, $connection->contracting_account_id);
            $this->assertSame(AccountContractingCertificate::where('account_id', $second->id)->sole()->id, $connection->account_contracting_certificate_id);
            $this->assertNull($connection->certificate_encrypted);
            $this->assertNull($connection->certificate_password_encrypted);
            $this->assertCompleteTuple($this->currentTuple('new', $this->oldCertificate), $this->oldCertificate);
        } else {
            $this->assertGreaterThanOrEqual(3, $result['discoveries'], 'One retry plus successful mutation and after-commit cache lookup.');
            $this->assertNull($connection->contracting_account_id);
            $this->assertNull($connection->account_contracting_certificate_id);
            $this->assertCompleteTuple($this->currentTuple('new'), $this->newCertificate);
        }
    }

    public static function commitOutcomes(): array
    {
        return ['commit' => [false], 'rollback' => [true]];
    }

    #[DataProvider('commitOutcomes')]
    public function test_snapshot_waits_for_atomic_source_and_connection_commit_or_rollback(bool $rollback): void
    {
        $account = $this->linkedConnection();
        $source = AccountContractingCertificate::sole();
        $beforeSource = $source->getRawOriginal();
        $beforeConnection = SerproConnection::sole()->getRawOriginal();
        $before = app(SerproCredentialLifecycle::class)->snapshot();
        Cache::put('serpro:token-pair:'.$before->generation(), 'existing-generation-marker', 60);
        $watcher = $this->worker('snapshot-watch', ['expected' => $this->expected('old')]);
        $this->waitFor($watcher, 'held');
        $writer = $this->worker('replace', ['account' => $account->id, 'source_id' => $source->id, 'certificate' => $this->newCertificate, 'pause' => 'source-update', 'rollback_source' => $rollback]);
        $this->waitFor($writer, 'held');
        $reader = $this->worker('snapshot', ['expected' => $this->expected('old', $rollback ? $this->oldCertificate : $this->newCertificate)]);
        $this->assertWaiting($reader, 'accounts');
        $this->release($writer);
        $written = $this->workerResult($writer);
        $this->assertSame($rollback ? 500 : 200, $written['status']);
        $read = $this->workerResult($reader);
        $this->release($watcher);
        $watched = $this->workerResult($watcher);
        $this->assertCompleteTuple($watched['tuple'], $this->oldCertificate);
        $this->assertSame($watched['tuple'], $watched['immutable_tuple']);
        $this->assertSame($rollback, $watched['still_current']);
        $this->assertCompleteTuple($read['tuple'], $rollback ? $this->oldCertificate : $this->newCertificate);
        $this->assertSame($rollback ? 1 : 2, $read['tuple']['connection_revision']);
        $this->assertSame($rollback ? 1 : 2, $read['tuple']['source_revision']);
        $this->assertSame('account', $read['tuple']['source']);
        $this->assertSame($source->id, $read['tuple']['source_id']);
        $this->assertSame($account->id, $read['tuple']['account_id']);
        $this->assertSame('active', $read['tuple']['account_status']);
        if ($rollback) {
            $this->assertTrue($beforeSource === $source->fresh()->getRawOriginal(), 'Raw source row changed after rollback.');
            $this->assertTrue($beforeConnection === SerproConnection::sole()->getRawOriginal(), 'Raw connection row changed after rollback.');
            $this->assertSame($before->generation(), $read['tuple']['generation']);
            $this->assertSame('existing-generation-marker', Cache::get('serpro:token-pair:'.$before->generation()));
        } else {
            $this->assertNotSame($before->generation(), $read['tuple']['generation']);
            $this->assertSame($source->fresh()->document, SerproConnection::sole()->contratante_numero);
        }
    }

    public static function upstreamResponses(): array
    {
        return ['success' => [200], 'refusal' => [401], 'unavailable' => [503]];
    }

    #[DataProvider('upstreamResponses')]
    public function test_diagnostic_detects_rotation_committed_while_provider_http_is_blocked(int $upstreamStatus): void
    {
        $this->uploadConnection();
        $old = app(SerproCredentialLifecycle::class)->snapshot();
        $rotation = $this->worker('save', $this->saveInput('new') + ['start_gate' => true]);
        $diagnostic = $this->worker('diagnostic', ['actor' => $this->admin->id, 'expected' => $this->expected('old'), 'block_http' => true, 'upstream_status' => $upstreamStatus]);
        $this->waitFor($diagnostic, 'http-entered');
        $this->release($rotation, 'start');
        $this->assertSame(200, $this->workerResult($rotation)['status']);
        $afterRotation = SerproConnection::sole()->getRawOriginal();
        $this->release($diagnostic, 'http-release');
        $result = $this->workerResult($diagnostic);
        $this->assertSame(409, $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('connection_changed', $result['code']);
        $this->assertDiagnosticHttp($result);
        $this->assertTrue($afterRotation === SerproConnection::sole()->getRawOriginal(), 'Diagnostic changed the rotated connection.');
        $this->assertNull(Cache::get('serpro:token-pair:'.$old->generation()));
        $this->assertNull(Cache::get('serpro:token-pair:'.app(SerproCredentialLifecycle::class)->snapshot()->generation()));
    }

    public static function invalidSources(): array
    {
        return ['suspended dedicated account' => ['suspend'], 'removed legacy certificate' => ['legacy-remove']];
    }

    #[DataProvider('invalidSources')]
    public function test_source_invalidation_during_diagnostic_fences_old_generation_and_future_auth(string $action): void
    {
        $account = $this->linkedConnection($action === 'legacy-remove');
        $before = app(SerproCredentialLifecycle::class)->snapshot();
        $seeded = $this->workerResult($this->worker('pair', ['expected' => $this->expected('old'), 'token_label' => 'old']));
        $this->assertSame(200, $seeded['status']);
        $this->assertSame(1, $seeded['http_calls']);
        $mutation = $this->worker($action, ['account' => $account->id, 'actor' => $this->admin->id, 'start_gate' => true]);
        $diagnostic = $this->worker('diagnostic', ['actor' => $this->admin->id, 'expected' => $this->expected('old'), 'block_http' => true]);
        $this->waitFor($diagnostic, 'http-entered');
        $this->release($mutation, 'start');
        $this->assertSame(200, $this->workerResult($mutation)['status']);
        $this->release($diagnostic, 'http-release');
        $result = $this->workerResult($diagnostic);
        $this->assertSame(409, $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('connection_changed', $result['code']);
        $this->assertDiagnosticHttp($result);
        $this->assertFalse(app(SerproCredentialLifecycle::class)->isCurrent($before));
        $this->assertFalse(app(SerproCredentialLifecycle::class)->snapshot()->hasUsableCertificate());
        $pair = $this->workerResult($this->worker('pair', ['expected' => $this->expected('old')]));
        $this->assertSame('do_not_retry', $pair['failure']);
        $this->assertSame(0, $pair['http_calls']);
        $local = $this->workerResult($this->worker('diagnostic', ['actor' => $this->admin->id, 'expected' => $this->expected('old')]));
        $this->assertSame(200, $local['status'], json_encode($local, JSON_THROW_ON_ERROR));
        $this->assertFalse($local['ok']);
        $this->assertSame('certificado', $local['failed_element']);
        $this->assertSame(0, $local['http_calls']);
        if ($action === 'suspend') {
            $source = AccountContractingCertificate::sole()->getRawOriginal();
            $connection = SerproConnection::sole()->getRawOriginal();
            $this->assertSame(422, $this->workerResult($this->worker('save', ['key' => 'new-key', 'secret' => 'new-secret', 'source' => 'account', 'account' => $account->id]))['status']);
            $this->assertSame(422, $this->workerResult($this->worker('replace', ['account' => $account->id, 'certificate' => $this->newCertificate]))['status']);
            $this->assertTrue($source === AccountContractingCertificate::sole()->getRawOriginal());
            $this->assertTrue($connection === SerproConnection::sole()->getRawOriginal());
        }
    }

    public static function staleAuthentications(): array
    {
        return ['late pair success' => ['pair', 200, 'not_sent'], 'late verify refusal' => ['verify', 401, 'do_not_retry']];
    }

    #[DataProvider('staleAuthentications')]
    public function test_late_old_auth_cannot_overwrite_or_forget_current_generation(string $action, int $upstream, string $failure): void
    {
        $this->uploadConnection();
        $old = app(SerproCredentialLifecycle::class)->snapshot();
        $rotation = $this->worker('save', $this->saveInput('new') + ['start_gate' => true]);
        $currentAuth = $this->worker('pair', ['expected' => $this->expected('new'), 'token_label' => 'new', 'start_gate' => true]);
        $oldAuth = $this->worker($action, ['expected' => $this->expected('old'), 'block_http' => true, 'upstream_status' => $upstream, 'token_label' => 'old']);
        $this->waitFor($oldAuth, 'http-entered');
        $this->release($rotation, 'start');
        $this->assertSame(200, $this->workerResult($rotation)['status']);
        $this->release($currentAuth, 'start');
        $currentResult = $this->workerResult($currentAuth);
        $this->assertSame(200, $currentResult['status']);
        $this->assertTrue($currentResult['pair_matches']);
        $this->assertSame(1, $currentResult['http_calls']);
        $generation = app(SerproCredentialLifecycle::class)->snapshot()->generation();
        $cached = Cache::get('serpro:token-pair:'.$generation);
        $this->assertNotNull($cached);
        $this->release($oldAuth, 'http-release');
        $oldResult = $this->workerResult($oldAuth);
        $this->assertSame($failure, $oldResult['failure']);
        $this->assertTrue($oldResult['materialized_cleaned']);
        $this->assertTrue($cached == Cache::get('serpro:token-pair:'.$generation));
        $this->assertNotSame($old->generation(), $generation);
        $reused = $this->workerResult($this->worker('pair', ['expected' => $this->expected('new'), 'token_label' => 'new']));
        $this->assertSame(200, $reused['status']);
        $this->assertTrue($reused['pair_matches']);
        $this->assertSame(0, $reused['http_calls']);
    }

    public function test_real_session_lock_ownership_loss_during_diagnostic_never_reports_success(): void
    {
        $this->uploadConnection();
        $before = SerproConnection::sole()->getRawOriginal();
        $snapshot = app(SerproCredentialLifecycle::class)->snapshot();
        $diagnostic = $this->worker('diagnostic', ['actor' => $this->admin->id, 'expected' => $this->expected('old'), 'block_http' => true]);
        $this->waitFor($diagnostic, 'http-entered');
        $lockKey = $this->signalData($diagnostic, 'session')['lock_key'];
        $replacement = $this->worker('take-session-lock', ['lock_key' => $lockKey]);
        $this->waitFor($replacement, 'held');
        $this->assertTrue($this->signalData($replacement, 'held')['acquired']);
        $this->release($diagnostic, 'http-release');
        $result = $this->workerResult($diagnostic);
        $this->release($replacement);
        $this->assertSame(200, $this->workerResult($replacement)['status']);
        $this->assertSame(503, $result['status']);
        $this->assertNull($result['ok']);
        $this->assertDiagnosticHttp($result);
        $this->assertTrue($before === SerproConnection::sole()->getRawOriginal());
        $this->assertNull(Cache::get('serpro:token-pair:'.$snapshot->generation()));
    }

    private function uploadConnection(): void
    {
        app(SerproConnectionManager::class)->save('old-key', 'old-secret', $this->upload($this->oldCertificate), $this->oldCertificate['password']);
    }

    private function linkedConnection(bool $legacy = false): Account
    {
        $account = Account::factory()->create();
        if ($legacy) {
            Queue::fake();
            app(CurrentTenant::class)->run($account->id, fn () => app(AccountCertificateVault::class)->replace($account, $this->upload($this->oldCertificate), $this->oldCertificate['password']));
            app(SerproConnectionManager::class)->save('old-key', 'old-secret', null, null, true);
        } else {
            app(AccountContractingCertificateVault::class)->replace($account, $this->upload($this->oldCertificate), $this->oldCertificate['password']);
            app(SerproConnectionManager::class)->save('old-key', 'old-secret', null, null, false, 'account', $account->id);
        }

        return $account;
    }

    private function saveInput(string $label): array
    {
        return ['key' => $label.'-key', 'secret' => $label.'-secret', 'certificate' => $label === 'old' ? $this->oldCertificate : $this->newCertificate, 'source' => 'upload'];
    }

    private function expected(string $label, ?array $certificate = null): array
    {
        return ['key' => $label.'-key', 'secret' => $label.'-secret', 'certificate' => $certificate ?? ($label === 'old' ? $this->oldCertificate : $this->newCertificate)];
    }

    private function currentTuple(string $label, ?array $certificate = null): array
    {
        return self::tuple(app(SerproCredentialLifecycle::class)->snapshot(), $this->expected($label, $certificate));
    }

    private function assertDiagnosticHttp(array $result): void
    {
        $this->assertSame(1, $result['http_calls']);
        $this->assertTrue($result['materialized_cleaned']);
        $this->assertTrue($result['no_store']);
        $this->assertLessThan(8, $result['elapsed']);
        foreach ($result['http_checks'] as $value) {
            $this->assertTrue($value);
        }
    }
}
