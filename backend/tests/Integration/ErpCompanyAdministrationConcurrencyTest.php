<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCompanyAdministrationConcurrencyTest extends TestCase
{
    private User $actor;

    private ErpCompany $company;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task2-20261018', getenv('COMPANY_ADMIN_ISOLATED'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertStringStartsWith('18.', DB::selectOne('show server_version')->server_version);
        $this->assertStringStartsWith('8.', Redis::connection('cache')->info('server')['redis_version']);
        Artisan::call('migrate:fresh', ['--force' => true]);
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
        $this->actor = User::factory()->create();
        $this->company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->actor->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->target = User::factory()->create();
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpCompanyAdministrationConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);

        return $result;
    }

    private function request(array $input): array
    {
        return $this->response($this->worker($input));
    }

    private function login(): array
    {
        $csrf = $this->request(['path' => '/api/v1/csrf']);
        $login = $this->request(['path' => '/api/v1/login', 'method' => 'POST', 'cookies' => $csrf['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']], 'body' => ['email' => $this->actor->email, 'password' => 'password']]);
        $this->assertSame(200, $login['status'], json_encode($login['body']));
        $csrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $login['cookies']]);

        return ['cookies' => $csrf['cookies'] ?: $login['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token'], 'X-Company-Id' => (string) $this->company->id]];
    }

    private function association(array $session): array
    {
        return $session + ['method' => 'POST', 'path' => '/api/v1/erp/members', 'body' => ['user_id' => $this->target->id, 'role' => 'operator', 'establishment_scope' => 'all']];
    }

    private function waitForDatabaseLock(string $label, string $ready): void
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$label])->count;
            if (file_exists($ready) && (int) $waiting === 1) {
                fwrite(STDERR, "Barrier confirmed: {$label}, PID ".file_get_contents($ready).", waiting on Company row lock\n");

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('HTTP worker did not reach the Company lock barrier: '.$label);
    }

    private function waitForWorkerPreboot(string $label, string $ready, Process $worker): int
    {
        $deadline = microtime(true) + 20;
        do {
            $contents = file_exists($ready) ? (string) file_get_contents($ready) : '';
            if ($contents !== '') {
                $facts = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($worker->getPid(), $facts['pid']);
                $this->assertSame($label, $facts['label']);
                $this->assertGreaterThan(0, $facts['backend_pid']);

                return (int) $facts['backend_pid'];
            }
            $this->assertTrue($worker->isRunning(), 'Worker exited before completing application bootstrap.');
            $this->assertLessThan($deadline, microtime(true), 'Worker did not complete application bootstrap: '.$label);
            usleep(10000);
        } while (true);
    }

    private function waitForCompanyRowWait(string $label, string $ready, Process $worker, int $holderPid): int
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select pid, wait_event_type from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$label]);
            $contents = file_exists($ready) ? (string) file_get_contents($ready) : '';
            if ($waiting && $contents !== '') {
                $facts = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($worker->getPid(), $facts['pid']);
                $this->assertSame($label, $facts['label']);
                $this->assertSame('company_for_update', $facts['barrier']);
                $this->assertSame($this->company->id, $facts['company_id']);
                $this->assertSame((int) $waiting->pid, (int) $facts['backend_pid']);
                $rooted = DB::selectOne(<<<'SQL'
                    with recursive blockers(pid) as (
                        select unnest(pg_blocking_pids(?::int))
                        union
                        select unnest(pg_blocking_pids(blockers.pid)) from blockers
                    )
                    select count(*) as count from blockers where pid = ?
                    SQL, [(int) $waiting->pid, $holderPid]);
                $this->assertSame(1, (int) $rooted->count, 'The real Company waiter must remain blocked by the parent transaction.');

                return (int) $waiting->pid;
            }
            $this->assertTrue($worker->isRunning(), 'Worker exited before waiting on the actual Company row lock.');
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('Worker did not reach the rooted Company row-lock wait: '.$label);
    }

    private function waitForIdentityAdvisoryWait(string $label, string $companyReady, Process $worker, int $workerBackendPid, int $firstBackendPid, int $holderPid): void
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $wait = DB::selectOne(<<<'SQL'
                with recursive waiting_activity as (
                    select pid, wait_event_type, wait_event from pg_stat_activity
                    where application_name = ? and wait_event_type = 'Lock'
                ), blockers(pid) as (
                    select unnest(pg_blocking_pids(waiting_activity.pid)) from waiting_activity
                    union
                    select unnest(pg_blocking_pids(blockers.pid)) from blockers
                )
                select waiting_activity.pid, waiting_activity.wait_event_type, waiting_activity.wait_event,
                    ? = any(pg_blocking_pids(waiting_activity.pid)) as blocked_by_first,
                    exists (
                        select 1 from pg_locks waiting
                        join pg_locks held on held.locktype = waiting.locktype
                            and held.database = waiting.database
                            and held.classid = waiting.classid
                            and held.objid = waiting.objid
                            and held.objsubid = waiting.objsubid
                        where waiting.pid = waiting_activity.pid and waiting.locktype = 'advisory'
                            and waiting.granted = false and waiting.objsubid = 1
                            and held.pid = ? and held.granted = true and held.mode = 'ExclusiveLock'
                    ) as waits_on_first_identity_lock,
                    exists (
                        select 1 from pg_locks relation_lock
                        where relation_lock.pid = waiting_activity.pid and relation_lock.granted = true
                            and relation_lock.relation = 'erp_companies'::regclass
                    ) as holds_company_relation_lock,
                    exists (select 1 from blockers where pid = ?) as blocker_chain_reaches_company_holder
                from waiting_activity
                SQL, [$label, $firstBackendPid, $firstBackendPid, $holderPid]);
            if ($wait !== null) {
                $this->assertSame($workerBackendPid, (int) $wait->pid);
                $this->assertSame('Lock', $wait->wait_event_type);
                $this->assertSame('advisory', $wait->wait_event);
                $this->assertTrue(in_array($wait->blocked_by_first, [true, 1, '1', 't'], true), 'The second request must wait directly on the first request.');
                $this->assertTrue(in_array($wait->waits_on_first_identity_lock, [true, 1, '1', 't'], true), 'The ungranted advisory lock tuple must match the first request\'s granted identity lock.');
                $this->assertTrue(in_array($wait->blocker_chain_reaches_company_holder, [true, 1, '1', 't'], true), 'The second request\'s blocker chain must reach the parent Company-lock holder.');
                $this->assertFalse(in_array($wait->holds_company_relation_lock, [true, 1, '1', 't'], true), 'Identity locks must be acquired before the Company relation lock.');
                $this->assertFileDoesNotExist($companyReady, 'The second request must not reach the Company lock while waiting for identity authority.');

                return;
            }
            $this->assertTrue($worker->isRunning(), 'Second request exited before its identity advisory-lock wait was observed.');
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('Second request did not wait on the first request identity lock: '.$label);
    }

    private function race(): array
    {
        $a = $this->login();
        $b = $this->login();
        $this->assertNotSame($a['cookies']['opmoni-session'], $b['cookies']['opmoni-session']);
        $readyA = tempnam(sys_get_temp_dir(), 'company-admin-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'company-admin-b-');
        $prebootReadyA = tempnam(sys_get_temp_dir(), 'company-admin-preboot-');
        $prebootReadyB = tempnam(sys_get_temp_dir(), 'company-admin-preboot-');
        $prebootReleaseA = tempnam(sys_get_temp_dir(), 'company-admin-preboot-');
        $prebootReleaseB = tempnam(sys_get_temp_dir(), 'company-admin-preboot-');
        unlink($readyA);
        unlink($readyB);
        unlink($prebootReadyA);
        unlink($prebootReadyB);
        unlink($prebootReleaseA);
        unlink($prebootReleaseB);
        $workerA = $workerB = null;
        try {
            $workerA = $this->worker($this->association($a) + [
                'label' => 'company-admin-race-a', 'ready' => $readyA, 'ready_facts' => true,
                'preboot_ready' => $prebootReadyA, 'preboot_release' => $prebootReleaseA,
            ]);
            $workerB = $this->worker($this->association($b) + [
                'label' => 'company-admin-race-b', 'ready' => $readyB, 'ready_facts' => true,
                'preboot_ready' => $prebootReadyB, 'preboot_release' => $prebootReleaseB,
            ]);
            $backendA = $this->waitForWorkerPreboot('company-admin-race-a', $prebootReadyA, $workerA);
            $backendB = $this->waitForWorkerPreboot('company-admin-race-b', $prebootReadyB, $workerB);

            DB::beginTransaction();
            DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
            $holderPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            touch($prebootReleaseA);
            $backendA = $this->waitForCompanyRowWait('company-admin-race-a', $readyA, $workerA, $holderPid);
            touch($prebootReleaseB);
            $this->waitForIdentityAdvisoryWait('company-admin-race-b', $readyB, $workerB, $backendB, $backendA, $holderPid);
            DB::commit();

            return [$this->response($workerA), $this->response($workerB)];
        } finally {
            @touch($prebootReleaseA);
            @touch($prebootReleaseB);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ([$workerA, $workerB] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            @unlink($readyA);
            @unlink($readyB);
            @unlink($prebootReadyA);
            @unlink($prebootReadyB);
            @unlink($prebootReleaseA);
            @unlink($prebootReleaseB);
        }
    }

    public function test_independent_owner_sessions_create_exactly_one_association(): void
    {
        [$a, $b] = $this->race();
        $statuses = [$a['status'], $b['status']];
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertNotSame($a['pid'], $b['pid']);
        $this->assertDatabaseCount('erp_company_memberships', 2);
        $this->assertSame(1, ErpCompanyMembership::where('user_id', $this->target->id)->count());
    }

    public function test_owner_demoted_while_request_waits_cannot_add_members(): void
    {
        $session = $this->login();
        $ready = tempnam(sys_get_temp_dir(), 'company-admin-demote-');
        unlink($ready);
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $worker = $this->worker($this->association($session) + ['label' => 'company-admin-demote', 'ready' => $ready]);
            $this->waitForDatabaseLock('company-admin-demote', $ready);
            ErpCompanyMembership::where('erp_company_id', $this->company->id)->where('user_id', $this->actor->id)->update(['role' => 'manager']);
            DB::commit();
            $this->assertSame(403, $this->response($worker)['status']);
            $this->assertDatabaseMissing('erp_company_memberships', ['user_id' => $this->target->id]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            @unlink($ready);
        }
    }

    public function test_lease_loss_and_validation_after_first_insert_roll_back_before_http_rendering(): void
    {
        foreach (['lease' => 503, 'validation' => 422] as $fault => $status) {
            $result = $this->request($this->association($this->login()) + ['fault' => $fault]);
            $this->assertSame($status, $result['status'], json_encode($result['body']));
            $this->assertDatabaseMissing('erp_company_memberships', ['user_id' => $this->target->id]);
            $session = $this->login();
            $result = $this->request($session + ['method' => 'POST', 'path' => '/api/v1/erp/establishments', 'body' => ['name' => 'Rollback branch', 'status' => 'active'], 'fault' => $fault, 'model' => 'establishment']);
            $this->assertSame($status, $result['status']);
            $this->assertDatabaseCount('erp_establishments', 0);
        }
    }

    public function test_lease_loss_after_member_update_and_delete_restores_original_membership(): void
    {
        $member = ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->target->id, 'role' => 'operator']);
        foreach (['updated' => 'PATCH', 'deleted' => 'DELETE'] as $event => $method) {
            $result = $this->request($this->login() + ['method' => $method, 'path' => '/api/v1/erp/members/'.$member->id, 'body' => $method === 'PATCH' ? ['role' => 'manager'] : [], 'fault' => 'lease', 'event' => $event]);
            $this->assertSame(503, $result['status']);
            $this->assertSame('operator', $member->fresh()->role);
        }
        $branch = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id, 'status' => 'active']);
        $result = $this->request($this->login() + ['method' => 'PATCH', 'path' => '/api/v1/erp/establishments/'.$branch->id, 'body' => ['status' => 'inactive'], 'fault' => 'lease', 'event' => 'updated', 'model' => 'establishment']);
        $this->assertSame(503, $result['status']);
        $this->assertSame('active', $branch->fresh()->status);
    }
}
