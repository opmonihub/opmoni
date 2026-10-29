<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpMemberInvitations;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class OfficeErpMemberConcurrencyTest extends OfficeErpCommercialProcessTestCase
{
    /** @var list<Process> */
    private array $memberWorkers = [];

    /** @var list<string> */
    private array $memberSignals = [];

    /** @var array<int, string> */
    private array $memberWorkerBases = [];

    /** @var array<string, array{ids:list<int|string>,hash:string}> */
    private array $memberTableSnapshots = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['office_erp_company_registrations', 'erp_company_member_invitations', 'office_erp_access_deliveries'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->memberTableSnapshots[$table] = [
                'ids' => $rows->pluck('id')->map(static fn ($id): int|string => is_int($id) ? $id : (string) $id)->all(),
                'hash' => hash('sha256', $rows->toJson()),
            ];
        }
    }

    public static function sourceHashes(): array
    {
        $paths = [
            'app/Erp/Commercial/OfficeErpMemberAuthority.php',
            'app/Erp/Commercial/OfficeErpCompanyMembers.php',
            'app/Erp/Commercial/OfficeErpMemberInvitations.php',
            'app/Erp/Commercial/OfficeErpMemberInvitationContinuation.php',
            'tests/Integration/OfficeErpMemberConcurrencyTest.php',
            'tests/Integration/OfficeErpMemberConcurrencyWorker.php',
        ];
        $hashes = [];
        foreach ($paths as $path) {
            $hash = hash_file('sha256', base_path($path));
            if (! is_string($hash)) {
                throw new \RuntimeException('Member concurrency source identity unavailable.');
            }
            $hashes[$path] = $hash;
        }

        return $hashes;
    }

    protected function tearDown(): void
    {
        foreach ($this->memberWorkers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        foreach ($this->memberSignals as $signal) {
            @unlink($signal);
        }
        foreach ($this->memberTableSnapshots as $table => $snapshot) {
            $rows = $snapshot['ids'] === [] ? collect() : DB::table($table)->whereIn('id', $snapshot['ids'])->orderBy('id')->get();
            self::assertSame($snapshot['hash'], hash('sha256', $rows->toJson()), 'Pre-existing member authority rows changed: '.$table);
        }
        parent::tearDown();
    }

    public function test_concurrent_invitation_issues_for_normalized_email_leave_one_pending_authority(): void
    {
        $fixture = $this->memberCompanyFixture();
        $barrier = $this->newBarrier();
        $email = $this->fixtureEmail($fixture, 'same-race');
        $first = $this->startMemberWorker($fixture + ['action' => 'issue', 'email' => ' '.strtoupper($email).' ', 'operation_key' => 'member-race-a-'.bin2hex(random_bytes(5))], $barrier);
        $second = $this->startMemberWorker($fixture + ['action' => 'issue', 'email' => $email, 'operation_key' => 'member-race-b-'.bin2hex(random_bytes(5))], $barrier);
        $this->releaseMemberWorkers($barrier);
        $results = [$this->memberWorkerResult($first), $this->memberWorkerResult($second)];

        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 200));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => in_array($result['status'], [409, 422], true)));
        self::assertSame(1, OfficeErpMemberInvitation::query()->where('account_id', $fixture['account_id'])
            ->where('erp_company_id', $fixture['company_id'])->where('email', $email)->where('status', 'pending')->count());
        self::assertSame(1, OfficeErpAccessDelivery::query()->where('account_id', $fixture['account_id'])
            ->where('erp_company_id', $fixture['company_id'])->where('purpose', 'member_invitation')->count());
    }

    public function test_accept_and_reissue_serialize_the_same_invitation_authority(): void
    {
        $fixture = $this->memberCompanyFixture();
        $recipient = User::factory()->create(['email' => $this->fixtureEmail($fixture, 'accept-reissue')]);
        $issued = $this->issue($fixture, $recipient->email);
        $token = OfficeErpAccessDelivery::query()->findOrFail($issued['delivery']['id'])->payload['token'];
        $barrier = $this->newBarrier();
        $accept = $this->startMemberWorker($fixture + [
            'action' => 'accept', 'recipient_id' => (int) $recipient->id, 'token' => $token,
        ], $barrier);
        $reissue = $this->startMemberWorker($fixture + [
            'action' => 'reissue', 'invitation_id' => (int) $issued['invitation']['id'],
            'operation_key' => 'member-reissue-race-'.bin2hex(random_bytes(5)),
        ], $barrier);
        $this->releaseMemberWorkers($barrier);
        $acceptResult = $this->memberWorkerResult($accept);
        $reissueResult = $this->memberWorkerResult($reissue);
        $invitation = OfficeErpMemberInvitation::query()->findOrFail($issued['invitation']['id']);

        if ($acceptResult['status'] === 200) {
            self::assertSame('accepted', $invitation->status);
            self::assertSame(409, $reissueResult['status']);
            self::assertDatabaseHas('erp_company_memberships', ['erp_company_id' => $fixture['company_id'], 'user_id' => $recipient->id]);
        } else {
            self::assertContains($acceptResult['status'], [403, 409, 410]);
            self::assertSame(200, $reissueResult['status']);
            self::assertSame('revoked', $invitation->status);
            self::assertDatabaseMissing('erp_company_memberships', ['erp_company_id' => $fixture['company_id'], 'user_id' => $recipient->id]);
            self::assertSame(1, OfficeErpMemberInvitation::query()->where('account_id', $fixture['account_id'])
                ->where('erp_company_id', $fixture['company_id'])->where('email', $recipient->email)->where('status', 'pending')->count());
        }
    }

    public function test_accept_and_revoke_have_one_terminal_invitation_authority(): void
    {
        $fixture = $this->memberCompanyFixture();
        $recipient = User::factory()->create(['email' => $this->fixtureEmail($fixture, 'accept-revoke')]);
        $issued = $this->issue($fixture, $recipient->email);
        $token = OfficeErpAccessDelivery::query()->findOrFail($issued['delivery']['id'])->payload['token'];
        $barrier = $this->newBarrier();
        $accept = $this->startMemberWorker($fixture + [
            'action' => 'accept', 'recipient_id' => (int) $recipient->id, 'token' => $token,
        ], $barrier);
        $revoke = $this->startMemberWorker($fixture + [
            'action' => 'revoke', 'invitation_id' => (int) $issued['invitation']['id'],
            'operation_key' => 'member-revoke-race-'.bin2hex(random_bytes(5)),
        ], $barrier);
        $this->releaseMemberWorkers($barrier);
        $acceptResult = $this->memberWorkerResult($accept);
        $revokeResult = $this->memberWorkerResult($revoke);
        $invitation = OfficeErpMemberInvitation::query()->findOrFail($issued['invitation']['id']);

        if ($acceptResult['status'] === 200) {
            self::assertSame('accepted', $invitation->status);
            self::assertSame(409, $revokeResult['status']);
            self::assertDatabaseHas('erp_company_memberships', ['erp_company_id' => $fixture['company_id'], 'user_id' => $recipient->id]);
        } else {
            self::assertContains($acceptResult['status'], [403, 409, 410]);
            self::assertSame(200, $revokeResult['status']);
            self::assertSame('revoked', $invitation->status);
            self::assertDatabaseMissing('erp_company_memberships', ['erp_company_id' => $fixture['company_id'], 'user_id' => $recipient->id]);
        }
    }

    public function test_recipient_promotion_and_issuer_role_loss_serialize_before_acceptance(): void
    {
        foreach (['promote-recipient', 'demote-issuer'] as $authorityChange) {
            $fixture = $this->memberCompanyFixture();
            $recipient = User::factory()->create(['email' => $this->fixtureEmail($fixture, 'authority-'.$authorityChange)]);
            $issued = $this->issue($fixture, $recipient->email);
            $token = OfficeErpAccessDelivery::query()->findOrFail($issued['delivery']['id'])->payload['token'];
            $barrier = $this->newBarrier();
            $accept = $this->startMemberWorker($fixture + [
                'action' => 'accept', 'recipient_id' => (int) $recipient->id, 'token' => $token,
            ], $barrier);
            $authority = $this->startMemberWorker($fixture + [
                'action' => $authorityChange, 'recipient_id' => (int) $recipient->id,
            ], $barrier);
            $this->releaseMemberWorkers($barrier);
            $acceptResult = $this->memberWorkerResult($accept);
            self::assertSame(200, $this->memberWorkerResult($authority)['status']);
            $invitation = OfficeErpMemberInvitation::query()->findOrFail($issued['invitation']['id']);
            $membershipExists = ErpCompanyMembership::query()->where('erp_company_id', $fixture['company_id'])->where('user_id', $recipient->id)->exists();

            if ($acceptResult['status'] === 200) {
                self::assertSame('accepted', $invitation->status);
                self::assertTrue($membershipExists);
            } else {
                self::assertContains($acceptResult['status'], [403, 409]);
                self::assertSame('pending', $invitation->status);
                self::assertFalse($membershipExists);
            }
        }
    }

    public function test_relationship_revocation_winning_company_lock_prevents_late_acceptance(): void
    {
        $fixture = $this->memberCompanyFixture();
        $recipient = User::factory()->create(['email' => $this->fixtureEmail($fixture, 'relationship')]);
        $issued = $this->issue($fixture, $recipient->email);
        $token = OfficeErpAccessDelivery::query()->findOrFail($issued['delivery']['id'])->payload['token'];

        $acceptBarrier = $this->newBarrier();
        $accept = $this->startMemberWorker($fixture + [
            'action' => 'accept', 'recipient_id' => (int) $recipient->id, 'token' => $token,
        ], $acceptBarrier);
        $acceptBase = $this->memberWorkerBases[spl_object_id($accept)];
        $revocation = $this->startMemberWorker($fixture + [
            'action' => 'hold-relationship-revoke', 'contract_id' => (int) $fixture['contracts'][0]->id,
        ], $this->newBarrier());
        $revokeBase = $this->memberWorkerBases[spl_object_id($revocation)];
        $this->waitForMemberSignal($revocation, $revokeBase.'.held');
        $this->releaseMemberWorkers($acceptBarrier);
        $attemptDeadline = microtime(true) + 10;
        while (! file_exists($acceptBase.'.attempting') && microtime(true) < $attemptDeadline) {
            usleep(5000);
        }
        self::assertFileExists($acceptBase.'.attempting');
        $acceptPid = (int) file_get_contents($acceptBase.'.attempting');
        $revokePid = (int) file_get_contents($revokeBase.'.held');
        $deadline = microtime(true) + 10;
        do {
            $wait = DB::selectOne('select wait_event_type, ? = any(pg_blocking_pids(pid)) as blocked from pg_stat_activity where pid = ?', [$revokePid, $acceptPid]);
            if ($wait !== null && in_array($wait->blocked, [true, 1, 't'], true)) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::assertNotNull($wait);
        self::assertTrue(in_array($wait->blocked, [true, 1, 't'], true), 'Acceptance must be blocked by the relationship revocation transaction.');
        self::assertContains($wait->wait_event_type, ['Lock', 'LWLock']);
        file_put_contents($revokeBase.'.release', 'go');
        self::assertSame(200, $this->memberWorkerResult($revocation)['status']);
        self::assertContains($this->memberWorkerResult($accept)['status'], [403, 404, 409, 410]);
        self::assertSame('canceled', DB::table('office_erp_contracts')->where('id', $fixture['contracts'][0]->id)->value('agreement_status'));
        self::assertSame('pending', OfficeErpMemberInvitation::query()->findOrFail($issued['invitation']['id'])->status);
        self::assertDatabaseMissing('erp_company_memberships', ['erp_company_id' => $fixture['company_id'], 'user_id' => $recipient->id]);
    }

    public function test_public_acceptance_races_legacy_owner_add_and_create_one_membership(): void
    {
        $fixture = $this->memberCompanyFixture();
        ErpSubscription::query()->where('erp_company_id', $fixture['company_id'])->update(['status' => 'active']);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $fixture['company_id'], 'user_id' => $fixture['actor_id'], 'role' => 'owner',
        ]);
        $recipient = User::factory()->create(['email' => $this->fixtureEmail($fixture, 'owner-add')]);
        $issued = $this->issue($fixture, $recipient->email);
        $token = OfficeErpAccessDelivery::query()->findOrFail($issued['delivery']['id'])->payload['token'];
        $acceptSession = $this->invitationRecipientSession($recipient, $token);
        $ownerSession = $this->ownerSession($fixture, (int) $fixture['company_id']);
        $barrier = $this->newBarrier();
        $accept = $this->startHttpWorker([
            ...$acceptSession, 'path' => '/api/v1/erp-member-invitations/accept', 'method' => 'POST',
            'body' => [], 'headers' => $acceptSession['headers'] + ['Idempotency-Key' => $acceptSession['operation_key']],
        ], $barrier);
        $ownerAdd = $this->startHttpWorker([
            ...$ownerSession, 'path' => '/api/v1/erp/members', 'method' => 'POST',
            'body' => ['user_id' => (int) $recipient->id, 'role' => 'viewer', 'establishment_scope' => 'all'],
            'headers' => $ownerSession['headers'] + ['X-Company-Id' => (string) $fixture['company_id']],
        ], $barrier);
        $this->releaseMemberWorkers($barrier);
        $accepted = $this->requestWorkerResult($accept);
        $owner = $this->requestWorkerResult($ownerAdd);

        self::assertTrue(($accepted['status'] === 200 && $owner['status'] === 409)
            || ($accepted['status'] === 409 && $owner['status'] === 201), sprintf(
                'Expected one authority winner; public accept status=%d code=%s, legacy Owner add status=%d code=%s.',
                $accepted['status'], (string) ($accepted['body']['code'] ?? ''),
                $owner['status'], (string) ($owner['body']['code'] ?? ''),
            ));
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $fixture['company_id'])->where('user_id', $recipient->id)->count());
        self::assertDatabaseHas('erp_company_memberships', [
            'erp_company_id' => $fixture['company_id'], 'user_id' => $fixture['actor_id'], 'role' => 'owner',
        ]);
        self::assertSame($accepted['status'] === 200 ? 'accepted' : 'pending', OfficeErpMemberInvitation::query()->findOrFail($issued['invitation']['id'])->status);
    }

    public function test_same_key_member_update_and_remove_replay_after_effect(): void
    {
        $fixture = $this->memberCompanyFixture();
        $member = User::factory()->create(['email' => $this->fixtureEmail($fixture, 'same-key-member')]);
        $membership = ErpCompanyMembership::factory()->create([
            'erp_company_id' => $fixture['company_id'], 'user_id' => $member->id, 'role' => 'operator',
        ]);
        $updateKey = 'member-update-replay-'.$fixture['member_email_suffix'];
        $updateInput = [...$fixture, 'action' => 'update', 'membership_id' => (int) $membership->id, 'operation_key' => $updateKey];
        $firstUpdate = $this->startMemberWorker($updateInput, $this->newBarrier());
        $this->releaseMemberWorkers($this->latestBarrier($firstUpdate));
        $firstUpdateResult = $this->memberWorkerResult($firstUpdate);
        $updateReplay = $this->startMemberWorker($updateInput, $this->newBarrier());
        $this->releaseMemberWorkers($this->latestBarrier($updateReplay));
        $updateReplayResult = $this->memberWorkerResult($updateReplay);
        self::assertSame(200, $firstUpdateResult['status']);
        self::assertSame($firstUpdateResult, $updateReplayResult);

        $removeKey = 'member-remove-replay-'.$fixture['member_email_suffix'];
        $removeInput = [...$fixture, 'action' => 'remove', 'membership_id' => (int) $membership->id, 'operation_key' => $removeKey];
        $firstRemoval = $this->startMemberWorker($removeInput, $this->newBarrier());
        $this->releaseMemberWorkers($this->latestBarrier($firstRemoval));
        $firstRemovalResult = $this->memberWorkerResult($firstRemoval);
        $removeReplay = $this->startMemberWorker($removeInput, $this->newBarrier());
        $this->releaseMemberWorkers($this->latestBarrier($removeReplay));
        $removeReplayResult = $this->memberWorkerResult($removeReplay);
        self::assertSame(200, $firstRemovalResult['status']);
        self::assertSame($firstRemovalResult, $removeReplayResult);
        self::assertSame(1, DB::table('office_erp_commercial_operations')->where('account_id', $fixture['account_id'])
            ->where('idempotency_key', $removeKey)->count());
    }

    /** @return array<string, mixed> */
    private function memberCompanyFixture(): array
    {
        $fixture = $this->commercialFixture(4);
        $contract = $fixture['contracts'][0];
        do {
            $cnpj = str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT);
        } while (DB::table('erp_companies')->where('cnpj', $cnpj)->exists());
        $company = ErpCompany::factory()->create(['cnpj' => $cnpj]);
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $fixture['account_id'], 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);

        return $fixture + ['company_id' => (int) $company->id, 'member_email_suffix' => bin2hex(random_bytes(8))];
    }

    /** @return array<string, mixed> */
    private function issue(array $fixture, string $email): array
    {
        return $this->officeAdmin($fixture, fn (): array => app(OfficeErpMemberInvitations::class)->issue(
            $fixture['context'], $fixture['company_id'], [
                'name' => 'Concurrency recipient', 'email' => $email, 'role' => 'viewer',
                'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => [],
            ], 'initial-member-issue-'.bin2hex(random_bytes(5)),
        ));
    }

    private function newBarrier(): string
    {
        $barrier = sys_get_temp_dir().'/opmoni-member-accept-race-'.bin2hex(random_bytes(12));
        $this->memberSignals[] = $barrier;

        return $barrier;
    }

    private function latestBarrier(Process $worker): string
    {
        $base = $this->memberWorkerBases[spl_object_id($worker)];

        return substr($base, 0, strrpos($base, '-worker-'));
    }

    private function startMemberWorker(array $input, string $barrier): Process
    {
        $input = array_intersect_key($input, array_flip([
            'action', 'account_id', 'actor_id', 'membership_id', 'company_id', 'contract_id', 'email', 'operation_key',
            'recipient_id', 'token', 'invitation_id',
        ]));
        $base = $barrier.'-worker-'.count($this->memberWorkers);
        $ready = $base.'.ready';
        $this->memberSignals = [...$this->memberSignals, $ready, $base.'.held', $base.'.release', $base.'.attempting'];
        $input['base'] = $base;
        $input['barrier'] = $barrier;
        $input['source_hashes'] = self::sourceHashes();
        $process = new Process([PHP_BINARY, base_path('tests/Integration/OfficeErpMemberConcurrencyWorker.php')], base_path(), [
            'APP_ENV' => 'testing', 'FISCAL_ENVIRONMENT' => 'producao', 'FRONTEND_ORIGIN' => 'http://localhost:3000',
        ]);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();
        $this->memberWorkers[] = $process;
        $this->memberWorkerBases[spl_object_id($process)] = $base;
        $deadline = microtime(true) + 15;
        while (! file_exists($ready)) {
            if (! $process->isRunning() || microtime(true) >= $deadline) {
                self::fail('Member worker did not become ready: '.hash('sha256', $process->getErrorOutput()));
            }
            usleep(5000);
        }
        $identity = json_decode((string) file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($input['source_hashes'], $identity['source_hashes']);
        if (($input['action'] ?? null) === 'accept') {
            self::assertTrue($identity['prepared'], 'Acceptance continuation must be prepared before releasing its race barrier.');
        }
        self::assertNotSame(getmypid(), $identity['pid']);
        self::assertNotSame((int) DB::selectOne('select pg_backend_pid() as pid')->pid, $identity['database_pid']);

        return $process;
    }

    private function fixtureEmail(array $fixture, string $purpose): string
    {
        return $purpose.'-'.$fixture['member_email_suffix'].'@example.test';
    }

    /** @return array{cookies:array<string,string>,headers:array<string,string>,operation_key:string} */
    private function invitationRecipientSession(User $recipient, string $token): array
    {
        $anonymous = $this->requestWorkerResult($this->startHttpWorker(['path' => '/api/v1/csrf'], $this->newBarrier(), true));
        $csrf = (string) $anonymous['body']['csrf_token'];
        $resolved = $this->requestWorkerResult($this->startHttpWorker([
            'path' => '/api/v1/erp-member-invitations/resolve', 'method' => 'POST',
            'body' => ['token' => $token], 'cookies' => $anonymous['cookies'],
            'headers' => ['X-CSRF-Token' => $csrf],
        ], $this->newBarrier(), true));
        self::assertSame(200, $resolved['status']);
        $login = $this->loginWorkerSession($recipient, $resolved['cookies']);
        $current = $this->requestWorkerResult($this->startHttpWorker([
            'path' => '/api/v1/erp-member-invitations/current', 'cookies' => $login['cookies'], 'headers' => $login['headers'],
        ], $this->newBarrier(), true));
        self::assertSame('ready', $current['body']['state']);

        return $login + ['operation_key' => (string) $current['body']['operation_key']];
    }

    /** @return array{cookies:array<string,string>,headers:array<string,string>} */
    private function ownerSession(array $fixture, int $companyId): array
    {
        $session = $this->loginWorkerSession($fixture['actor'], []);
        $switch = $this->requestWorkerResult($this->startHttpWorker([
            'path' => '/api/v1/erp/company/switch', 'method' => 'POST', 'body' => ['company_id' => $companyId],
            'cookies' => $session['cookies'], 'headers' => $session['headers'],
        ], $this->newBarrier(), true));
        self::assertSame(200, $switch['status']);

        return $this->workerCsrfSession($switch['cookies'], [
            'X-Account-Id' => (string) $fixture['account_id'], 'X-Company-Id' => (string) $companyId,
        ]);
    }

    /** @return array{cookies:array<string,string>,headers:array<string,string>} */
    private function loginWorkerSession(User $user, array $cookies): array
    {
        $csrf = $this->requestWorkerResult($this->startHttpWorker([
            'path' => '/api/v1/csrf', 'cookies' => $cookies,
        ], $this->newBarrier(), true));
        $login = $this->requestWorkerResult($this->startHttpWorker([
            'path' => '/api/v1/login', 'method' => 'POST', 'body' => ['email' => $user->email, 'password' => 'password'],
            'cookies' => $csrf['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']],
        ], $this->newBarrier(), true));
        self::assertSame(200, $login['status']);

        return $this->workerCsrfSession($login['cookies'], isset($login['body']['current_account']['id'])
            ? ['X-Account-Id' => (string) $login['body']['current_account']['id']]
            : []);
    }

    /** @return array{cookies:array<string,string>,headers:array<string,string>} */
    private function workerCsrfSession(array $cookies, array $extraHeaders = []): array
    {
        $csrf = $this->requestWorkerResult($this->startHttpWorker([
            'path' => '/api/v1/csrf', 'cookies' => $cookies,
        ], $this->newBarrier(), true));

        return ['cookies' => $csrf['cookies'] ?: $cookies, 'headers' => $extraHeaders + ['X-CSRF-Token' => (string) $csrf['body']['csrf_token']]];
    }

    private function startHttpWorker(array $input, string $barrier, bool $released = false): Process
    {
        $base = $barrier.'-http-'.count($this->memberWorkers);
        $started = $base.'.started';
        $this->memberSignals[] = $started;
        $process = new Process([PHP_BINARY, base_path('tests/Integration/request-worker.php')], base_path());
        $this->memberWorkerBases[spl_object_id($process)] = $base;
        $input['started'] = $started;
        if (! $released) {
            $input['barrier'] = $barrier;
        }
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();
        $this->memberWorkers[] = $process;
        if ($released) {
            file_put_contents($barrier, 'go');
        } else {
            $this->waitForMemberSignal($process, $started);
        }

        return $process;
    }

    /** @return array<string,mixed> */
    private function requestWorkerResult(Process $worker): array
    {
        $worker->wait();
        self::assertTrue($worker->isSuccessful(), 'Request worker failed: '.hash('sha256', $worker->getErrorOutput()));

        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitForMemberSignal(Process $process, string $signal): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($signal)) {
            if (! $process->isRunning() || microtime(true) >= $deadline) {
                self::fail('Member worker did not reach synchronization point.');
            }
            usleep(5000);
        }
    }

    private function releaseMemberWorkers(string $barrier): void
    {
        file_put_contents($barrier, 'go');
    }

    /** @return array{status:int,result:array<string,mixed>} */
    private function memberWorkerResult(Process $worker): array
    {
        $worker->wait();
        self::assertTrue($worker->isSuccessful(), 'Member worker failed: '.hash('sha256', $worker->getErrorOutput()));
        $output = $worker->getOutput();
        $result = json_decode($output, true);
        self::assertIsArray($result, sprintf(
            'Member worker output was not a JSON object (bytes=%d, sha256=%s, stderr_bytes=%d, stderr_sha256=%s).',
            strlen($output), hash('sha256', $output), strlen($worker->getErrorOutput()), hash('sha256', $worker->getErrorOutput()),
        ));

        return $result;
    }
}
