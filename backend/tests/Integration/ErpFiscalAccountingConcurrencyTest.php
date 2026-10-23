<?php

namespace Tests\Integration;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Fiscal\Read\FiscalDocumentReadiness;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\Support\BuildsVerifiedErpFiscalEvidence;
use Tests\TestCase;

class ErpFiscalAccountingConcurrencyTest extends TestCase
{
    use ApprovesErpAccountingMappings;
    use BuildsVerifiedErpFiscalEvidence;

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        config(['erp.fiscal_certificate_relaxed_validation' => true]);
        if (! self::$schemaReady) {
            $lockPath = sys_get_temp_dir().'/opmoni-pgsql-migrate.lock';
            $lock = fopen($lockPath, 'c+');
            flock($lock, LOCK_EX);
            try {
                Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
                self::$schemaReady = true;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
    }

    public function test_postgresql_concurrency_contracts_for_fiscal_and_accounting(): void
    {
        $this->assertConcurrentDeliveryPublishesSingleOfficeDocument();
        $this->assertConcurrentTransmitAuthorizesOnce();
        $this->assertDeliveryBlockedWhenDelegationRevoked();
    }

    private function assertConcurrentDeliveryPublishesSingleOfficeDocument(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj()]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $company->cnpj]);
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
        ]);
        $client = Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => $company->cnpj,
        ]);

        $mapping = $this->approveAccountingMapping($delegation, $establishment, $client);
        $path = 'erp/fiscal/'.$company->id.'/900/authorized.xml';
        Storage::disk('local')->put($path, '<nfeProc/>');
        $documentId = (int) DB::table('erp_fiscal_documents')->insertGetId([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 9100,
            'access_key' => '35251030288513000100550010000091001234567890',
            'status' => ErpFiscalDocumentStateMachine::AUTHORIZED,
            'idempotency_key' => 'acct-race-doc',
            'storage_path' => $path,
            'artifact_byte_length' => Storage::disk('local')->size($path),
            'artifact_sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'authorized_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $verifiedDocument = ErpFiscalDocument::findOrFail($documentId);
        $this->attachVerifiedFiscalEvidence($verifiedDocument);
        $deliveryId = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($verifiedDocument)->id;

        $results = $this->concurrent([
            ['operation' => 'deliver', 'delivery_id' => $deliveryId],
            ['operation' => 'deliver', 'delivery_id' => $deliveryId],
        ]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertGreaterThanOrEqual(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));

        $this->assertSame(1, ErpAccountingDelivery::query()->whereKey($deliveryId)->where('status', ErpAccountingDelivery::STATUS_DELIVERED)->count());
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $verifiedDocument->access_key)->count());
    }

    private function assertConcurrentTransmitAuthorizesOnce(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj(), 'operating_settings' => ['uf' => 'SP']]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $company->cnpj]);
        $document = ErpFiscalDocument::create(['erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id,
            'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => random_int(30000, 39999), 'idempotency_key' => 'fiscal-transmit-race-1']);
        $this->attachVerifiedFiscalEvidence($document);
        $attempt = $document->attempts()->where('action', 'document:transmit')->sole();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $attempt->actor_user_id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        Storage::disk('local')->delete($document->storage_path);
        $document->update(['status' => 'queued', 'authorized_at' => null, 'storage_path' => null, 'artifact_sha256' => null,
            'artifact_byte_length' => null, 'metadata' => []]);
        $attempt->forceFill(['status' => 'pending', 'outbox_state' => 'new', 'safe_result' => null, 'provider_protocol' => null,
            'dispatch_attempts' => 0, 'available_at' => now()])->save();
        $calls = sys_get_temp_dir().'/opmoni-fiscal-provider-calls-'.bin2hex(random_bytes(8));
        try {
            $results = $this->concurrent([
                ['operation' => 'fiscal-transmit', 'attempt_id' => $attempt->id, 'provider_calls' => $calls],
                ['operation' => 'fiscal-transmit', 'attempt_id' => $attempt->id, 'provider_calls' => $calls],
            ]);
            $this->assertTrue(collect($results)->every(fn (array $row): bool => $row['ok']), json_encode($results));
            $this->assertCount(2, array_unique(array_column($results, 'backend_pid')));
            $this->assertSame(1, $attempt->fresh()->dispatch_attempts);
            $this->assertSame('accepted', $attempt->fresh()->status);
            $this->assertSame('completed', $attempt->fresh()->outbox_state);
            $this->assertCount(1, file($calls, FILE_IGNORE_NEW_LINES));
            $this->assertSame('authorized', $document->fresh()->status);
            $this->assertTrue(app(ErpFiscalArtifactStore::class)->verifiedFiscalArtifactReady($document->fresh()));
        } finally {
            @unlink($calls);
        }
    }

    private function assertDeliveryBlockedWhenDelegationRevoked(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj()]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $company->cnpj]);
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
        ]);
        $client = Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => $company->cnpj,
        ]);
        $mapping = $this->approveAccountingMapping($delegation, $establishment, $client);
        $path = 'erp/fiscal/'.$company->id.'/920/authorized.xml';
        Storage::disk('local')->put($path, '<nfeProc/>');
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 9200,
            'access_key' => '35251030288513000100550010000092001234567890',
            'status' => ErpFiscalDocumentStateMachine::AUTHORIZED,
            'idempotency_key' => 'acct-revoke-doc',
            'storage_path' => $path,
            'artifact_byte_length' => Storage::disk('local')->size($path),
            'artifact_sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'authorized_at' => now(),
        ]);
        $verifiedXml = $this->attachVerifiedFiscalEvidence($document);
        $key = $document->access_key;
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($document);
        $this->assertNotNull($delivery);
        $delegation->update(['status' => 'revoked']);

        $worker = $this->worker(['operation' => 'deliver', 'delivery_id' => $delivery->id]);
        $worker->start();
        $result = $this->response($worker);
        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(ErpAccountingDelivery::STATUS_BLOCKED, ErpAccountingDelivery::query()->findOrFail($delivery->id)->status);
    }

    public function test_crossing_parent_locks_fail_fast_and_rollback_without_consuming_attempts(): void
    {
        $s = $this->mappingScenario();
        $prefix = sys_get_temp_dir().'/opmoni-mapping-cross-'.bin2hex(random_bytes(6));
        $office = $this->worker($s + ['operation' => 'cross-approve', 'parent_lock' => 'account', 'ready' => $prefix.'-office', 'release' => $prefix.'-go']);
        $company = $this->worker($s + ['operation' => 'cross-retry', 'parent_lock' => 'company', 'ready' => $prefix.'-company', 'release' => $prefix.'-go']);
        $office->start();
        $company->start();
        try {
            $this->awaitFile($prefix.'-office');
            $this->awaitFile($prefix.'-company');
            touch($prefix.'-go');
            $results = [$this->response($office), $this->response($company)];
            $busy = array_values(array_filter($results, fn ($result) => ($result['http_status'] ?? null) === 503));
            $this->assertNotEmpty($busy, json_encode($results));
            foreach ($busy as $result) {
                $this->assertSame('accounting_mapping_busy', $result['body']['code']);
                $this->assertSame('1', $result['retry_after']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertSame(1, $result['usable']);
                $this->assertLessThan(2, $result['elapsed']);
            }
            $this->assertSame(0, ErpAccountingDelivery::findOrFail($s['delivery_id'])->attempts);
            $this->assertSame(1, ErpAccountingMapping::where('delegation_id', $s['delegation_id'])->count());
            $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->count());
        } finally {
            touch($prefix.'-go');
            $office->stop();
            $company->stop();
            foreach (glob($prefix.'*') as $path) {
                unlink($path);
            }
        }
    }

    public function test_equivalent_approval_processes_return_same_history_row(): void
    {
        $s = $this->mappingScenario();
        $results = $this->concurrent([$s + ['operation' => 'approve'], $s + ['operation' => 'approve']]);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
            $this->assertSame($s['mapping_id'], $result['mapping_id']);
        }
        $this->assertSame(1, ErpAccountingMapping::where('delegation_id', $s['delegation_id'])->count());
    }

    public function test_initial_concurrent_office_approvals_create_one_mapping(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj()]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $company->cnpj]);
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id]);
        $client = resolve(CurrentTenant::class)->run($office->id, fn () => Client::factory()->company()->create(['account_id' => $office->id]));
        $approver = User::factory()->create();
        $membership = AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $approver->id, 'role' => 'admin']);
        $this->assertSame(0, ErpAccountingMapping::where('delegation_id', $delegation->id)->count());
        $input = ['operation' => 'approve', 'account_id' => $office->id, 'membership_id' => $membership->id, 'delegation_id' => $delegation->id, 'establishment_id' => $establishment->id, 'client_id' => $client->id];
        $results = $this->concurrent([$input, $input]);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }
        $this->assertSame($results[0]['mapping_id'], $results[1]['mapping_id']);
        $mapping = ErpAccountingMapping::where('delegation_id', $delegation->id)->sole();
        $this->assertSame($results[0]['mapping_id'], $mapping->id);
        $this->assertSame(1, $mapping->revision);
        $this->assertSame('active', $mapping->status);
        $this->assertSame($approver->id, $mapping->approved_by_user_id);
    }

    public function test_new_intent_http_busy_failure_rolls_back_intent_and_job(): void
    {
        $s = $this->mappingScenario();
        ErpAccountingDelivery::whereKey($s['delivery_id'])->delete();
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $s['company_id'], 'user_id' => $owner->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $s['company_id'], 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $session = $this->httpLogin($owner);
        $intents = ErpAccountingDelivery::count();
        $jobs = DB::table('jobs')->count();
        DB::transaction(function () use ($s, $session): void {
            Account::whereKey($s['account_id'])->lockForUpdate()->firstOrFail();
            $response = $this->httpRequest(array_replace_recursive($session, [
                'method' => 'POST', 'path' => '/api/v1/erp/accounting-deliveries',
                'headers' => ['X-Company-Id' => (string) $s['company_id']],
                'body' => ['erp_fiscal_document_id' => $s['document_id']],
            ]));
            $this->assertSame(503, $response['status']);
            $this->assertSame('accounting_mapping_busy', $response['body']['code']);
            $this->assertSame('1', $response['retry_after']);
        });
        $this->assertSame($intents, ErpAccountingDelivery::count());
        $this->assertSame($jobs, DB::table('jobs')->count());
        $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->count());
    }

    public function test_new_intent_http_invalid_prerequisite_rolls_back_intent_and_job(): void
    {
        $s = $this->mappingScenario();
        ErpAccountingDelivery::whereKey($s['delivery_id'])->delete();
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $s['company_id'], 'user_id' => $owner->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $s['company_id'], 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $session = $this->httpLogin($owner);
        $intents = ErpAccountingDelivery::count();
        $jobs = DB::table('jobs')->count();
        foreach (['inactive_client', 'missing_storage'] as $failure) {
            Client::withoutGlobalScope('account')->whereKey($s['client_id'])->update(['status' => $failure === 'inactive_client' ? 'inactive' : 'active']);
            if ($failure === 'missing_storage') {
                Storage::disk('local')->delete(ErpFiscalDocument::findOrFail($s['document_id'])->storage_path);
            }
            $response = $this->httpRequest(array_replace_recursive($session, [
                'method' => 'POST', 'path' => '/api/v1/erp/accounting-deliveries',
                'headers' => ['X-Company-Id' => (string) $s['company_id']],
                'body' => ['erp_fiscal_document_id' => $s['document_id']],
            ]));
            $this->assertSame(422, $response['status'], $failure);
            $this->assertSame($intents, ErpAccountingDelivery::count(), $failure);
            $this->assertSame($jobs, DB::table('jobs')->count(), $failure);
            $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->count());
        }
    }

    public function test_parent_fenced_revocation_and_status_races_prevent_late_publication(): void
    {
        foreach (['mapping' => 'mapping_revoked', 'delegation' => 'delegation_revoked', 'company' => 'company_inactive', 'account' => 'office_inactive', 'client' => 'client_inactive', 'delete-client' => 'client_inactive', 'source' => 'fiscal_document_not_authorized'] as $mutation => $reason) {
            $s = $this->mappingScenario();
            $prefix = sys_get_temp_dir().'/opmoni-mapping-revoke-'.bin2hex(random_bytes(6));
            $accountFence = in_array($mutation, ['account', 'client', 'delete-client'], true);
            $target = match ($mutation) {
                'mapping' => $s['mapping_id'], 'delegation' => $s['delegation_id'], 'company' => $s['company_id'], 'account' => $s['account_id'], 'source' => $s['document_id'], default => $s['client_id']
            };
            $mutator = $this->worker($s + ['operation' => 'mutate', 'mutation' => $mutation, 'target_id' => $target, 'parent_lock' => $accountFence ? 'account' : 'company', 'ready' => $prefix.'-ready', 'release' => $prefix.'-go']);
            $mutator->start();
            try {
                $this->awaitFile($prefix.'-ready');
                $delivery = $this->worker($s + ['operation' => 'deliver', 'entered' => $prefix.'-entered']);
                $delivery->start();
                $this->awaitFile($prefix.'-entered');
                if ($accountFence) {
                    $busy = $this->response($delivery);
                    $this->assertSame('accounting_mapping_busy', $busy['body']['code'], json_encode($busy));
                }
                touch($prefix.'-go');
                $mutated = $this->response($mutator);
                $this->assertTrue($mutated['ok'], json_encode($mutated));
                if (! $accountFence) {
                    $result = $this->response($delivery);
                } else {
                    $delivery = $this->worker($s + ['operation' => 'deliver']);
                    $delivery->start();
                    $result = $this->response($delivery);
                }
                $this->assertSame($reason, $result['reason'], json_encode($result));
                $this->assertSame(0, $result['attempts']);
                $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->count());
            } finally {
                touch($prefix.'-go');
                $mutator->stop();
                if (isset($delivery)) {
                    $delivery->stop();
                }
                foreach (glob($prefix.'*') as $path) {
                    unlink($path);
                }
            }
        }
    }

    public function test_actual_neutral_worker_publishes_after_commit_and_regrant_converges(): void
    {
        $s = $this->mappingScenario();
        config(['queue.default' => 'database']);
        $delivery = ErpAccountingDelivery::findOrFail($s['delivery_id']);
        $foreignOffice = Account::factory()->create();
        $membership = AccountMembership::factory()->create(['account_id' => $foreignOffice->id]);
        request()->attributes->set(CurrentAccount::class, new CurrentAccount($foreignOffice, $membership));
        DB::transaction(function () use ($delivery): void {
            $result = app(ErpAccountingHandoffService::class)->retry($delivery);
            $this->assertSame('pending', $result->status);
            $this->assertSame(0, DB::table('jobs')->where('queue', 'default')->count());
        });
        request()->attributes->remove(CurrentAccount::class);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->runQueueWorker();
        $delivered = $delivery->fresh();
        $this->assertSame('delivered', $delivered->status);
        $history = $delivered->getAttributes();
        ErpOfficeDelegation::findOrFail($s['delegation_id'])->update(['status' => 'revoked']);
        $newGrant = ErpOfficeDelegation::factory()->create(['erp_company_id' => $s['company_id'], 'account_id' => $s['account_id']]);
        $this->approveAccountingMapping($newGrant, ErpEstablishment::findOrFail($s['establishment_id']), Client::withoutGlobalScope('account')->findOrFail($s['client_id']));
        $newIntent = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent(ErpFiscalDocument::findOrFail($s['document_id']), $newGrant->authorized_by_user_id);
        app(ErpAccountingHandoffService::class)->retry($newIntent);
        $this->runQueueWorker();
        $this->assertNotSame($delivered->id, $newIntent->id);
        $this->assertSame($delivered->fiscal_document_id, $newIntent->fresh()->fiscal_document_id);
        $this->assertSame($history, $delivered->fresh()->getAttributes());
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->count());
        $previous = $newIntent->fresh();
        $mapping = ErpAccountingMapping::findOrFail($previous->mapping_id);
        $mapping->update(['status' => 'revoked', 'revoked_at' => now()]);
        $nextMapping = $this->approveAccountingMapping($newGrant, ErpEstablishment::findOrFail($s['establishment_id']), Client::withoutGlobalScope('account')->findOrFail($s['client_id']));
        $nextIntent = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent(ErpFiscalDocument::findOrFail($s['document_id']), $newGrant->authorized_by_user_id);
        $this->assertNotSame($previous->id, $nextIntent->id);
        $this->assertSame($nextMapping->id, $nextIntent->mapping_id);
        app(ErpAccountingHandoffService::class)->retry($nextIntent);
        $this->runQueueWorker();
        $this->assertSame($previous->fiscal_document_id, $nextIntent->fresh()->fiscal_document_id);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_capture_and_handoff_processes_converge_to_one_ready_document(): void
    {
        $s = $this->mappingScenario();
        $results = $this->concurrent([$s + ['operation' => 'capture'], $s + ['operation' => 'deliver']]);
        $this->assertOnlySuccessOrBusy($results);
        $result = app(ErpAccountingHandoffService::class)->deliver(ErpAccountingDelivery::findOrFail($s['delivery_id']));
        $this->assertSame('delivered', $result->status, json_encode($results));
        $rows = FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->get();
        $this->assertCount(1, $rows);
        $this->assertTrue(app(FiscalDocumentReadiness::class)->isReadyRow($rows->sole()));
        $this->assertSame($s['bytes'], Storage::disk('fiscal')->get($rows->sole()->storage_path));
    }

    public function test_real_queue_defers_busy_crossing_without_attempt_then_delivers(): void
    {
        $s = $this->mappingScenario();
        config(['queue.default' => 'database']);
        $delivery = ErpAccountingDelivery::findOrFail($s['delivery_id']);
        app(ErpAccountingHandoffService::class)->retry($delivery);
        DB::transaction(function () use ($s, $delivery): void {
            Account::whereKey($s['account_id'])->lockForUpdate()->firstOrFail();
            $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'database', '--queue=default', '--once', '--sleep=0', '--timeout=20'], base_path(), ['QUEUE_CONNECTION' => 'database']);
            $worker->setTimeout(30);
            $worker->run();
            $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertSame('pending', $delivery->fresh()->status);
            $this->assertSame(0, $delivery->fresh()->attempts);
            $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
            $this->assertGreaterThan(time(), (int) DB::table('jobs')->where('queue', 'default')->value('available_at'));
        });
        DB::table('jobs')->where('queue', 'default')->update(['available_at' => time()]);
        $this->runQueueWorker();
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_real_http_busy_responses_unwind_office_and_company_middleware_transactions(): void
    {
        $s = $this->mappingScenario();
        $member = AccountMembership::findOrFail($s['membership_id']);
        $officeSession = $this->httpLogin(User::findOrFail($member->user_id));
        DB::transaction(function () use ($s, $officeSession): void {
            ErpCompany::whereKey($s['company_id'])->lockForUpdate()->firstOrFail();
            $busy = $this->httpRequest(array_replace_recursive($officeSession, [
                'method' => 'POST', 'path' => '/api/v1/account/erp-accounting-mappings',
                'headers' => ['X-Account-Id' => (string) $s['account_id']],
                'body' => ['delegation_id' => $s['delegation_id'], 'erp_establishment_id' => $s['establishment_id'], 'client_id' => $s['client_id']],
            ]));
            $this->assertSame(503, $busy['status'], json_encode($busy));
            $this->assertSame('accounting_mapping_busy', $busy['body']['code']);
            $this->assertSame('1', $busy['retry_after']);
        });
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $s['company_id'], 'user_id' => $owner->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $s['company_id'], 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $ownerSession = $this->httpLogin($owner);
        DB::transaction(function () use ($s, $ownerSession): void {
            Account::whereKey($s['account_id'])->lockForUpdate()->firstOrFail();
            $busy = $this->httpRequest(array_replace_recursive($ownerSession, [
                'method' => 'POST', 'path' => '/api/v1/erp/accounting-deliveries/'.$s['delivery_id'].'/retry',
                'headers' => ['X-Company-Id' => (string) $s['company_id'], 'Idempotency-Key' => 'accounting-busy-retry'],
            ]));
            $this->assertSame(503, $busy['status'], json_encode($busy));
            $this->assertSame('accounting_mapping_busy', $busy['body']['code']);
            $this->assertSame('1', $busy['retry_after']);
        });
        $this->assertSame(0, ErpAccountingDelivery::findOrFail($s['delivery_id'])->attempts);
        $this->assertSame(1, ErpAccountingMapping::where('delegation_id', $s['delegation_id'])->count());
        $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->count());
    }

    private function httpLogin(User $user): array
    {
        $csrf = $this->httpRequest(['path' => '/api/v1/csrf']);
        $this->assertSame(200, $csrf['status']);
        $login = $this->httpRequest(['path' => '/api/v1/login', 'method' => 'POST', 'cookies' => $csrf['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']], 'body' => ['email' => $user->email, 'password' => 'password']]);
        $this->assertSame(200, $login['status'], json_encode($login));
        $csrf = $this->httpRequest(['path' => '/api/v1/csrf', 'cookies' => $login['cookies']]);

        return ['cookies' => $csrf['cookies'] ?: $login['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']]];
    }

    private function httpRequest(array $input): array
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Integration/request-worker.php')], base_path(), ['ERP_OPERATING_WRITES_ENABLED' => 'true', 'ERP_RELEASE_ENABLED' => 'true', 'QUEUE_CONNECTION' => 'database']);
        $worker->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $worker->setTimeout(30);
        $worker->start();

        return $this->response($worker);
    }

    public function test_source_first_intent_creation_and_publication_share_original_placeholder(): void
    {
        $s = $this->mappingScenario();
        $intent = ErpAccountingDelivery::findOrFail($s['delivery_id']);
        $intent->update(['mapping_id' => null, 'mapping_revision' => null, 'status' => 'blocked', 'reason_code' => 'client_mapping_missing']);
        $results = $this->concurrent([$s + ['operation' => 'ensure'], $s + ['operation' => 'deliver']]);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }
        $this->assertSame($intent->id, $results[0]['intent_id']);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame($s['mapping_id'], $intent->fresh()->mapping_id);
        $this->assertSame(1, ErpAccountingDelivery::where('erp_fiscal_document_id', $s['document_id'])->count());
    }

    public function test_conflicting_capture_race_preserves_first_accepted_artifact(): void
    {
        $s = $this->mappingScenario();
        $results = $this->concurrent([array_replace($s, ['operation' => 'capture', 'bytes' => '<conflicting/>']), $s + ['operation' => 'deliver']]);
        $this->assertOnlySuccessOrBusy($results);
        app(ErpAccountingHandoffService::class)->deliver(ErpAccountingDelivery::findOrFail($s['delivery_id']));
        $row = FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', $s['key'])->sole();
        $this->assertSame('attention', $row->processing_state->value);
        $this->assertSame('artifact_conflict', $row->processing_reason->value);
        $bytes = Storage::disk('fiscal')->get($row->storage_path);
        $this->assertSame($row->sha256, hash('sha256', $bytes));
        $this->assertSame($row->xml_bytes, strlen($bytes));
        $this->assertContains($bytes, [$s['bytes'], '<conflicting/>']);
    }

    private function assertOnlySuccessOrBusy(array $results): void
    {
        $this->assertNotEmpty(array_filter($results, fn ($result) => $result['ok']), json_encode($results));
        foreach ($results as $result) {
            if (! $result['ok']) {
                $this->assertSame(503, $result['http_status'] ?? null, json_encode($result));
                $this->assertSame('accounting_mapping_busy', $result['body']['code']);
            }
        }
    }

    private function runQueueWorker(): void
    {
        $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'database', '--queue=default', '--stop-when-empty', '--sleep=0', '--tries=10', '--timeout=20'], base_path(), ['QUEUE_CONNECTION' => 'database']);
        $worker->setTimeout(30);
        $worker->run();
        $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertFileExists($path);
    }

    private function mappingScenario(): array
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj()]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $company->cnpj]);
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id]);
        $client = resolve(CurrentTenant::class)->run($office->id, fn () => Client::factory()->company()->create(['account_id' => $office->id]));
        $mapping = $this->approveAccountingMapping($delegation, $establishment, $client);
        $base = '3525103028851300010055001'.str_pad((string) $company->id, 43 - strlen('3525103028851300010055001'), '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split(strrev($base)) as $index => $digit) {
            $sum += (int) $digit * (2 + $index % 8);
        }
        $key = $base.($sum % 11 < 2 ? 0 : 11 - $sum % 11);
        $path = 'erp/fiscal/mapping-process/'.$company->id.'.xml';
        Storage::disk('local')->put($path, '<nfeProc/>');
        $document = ErpFiscalDocument::create([
            'erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id,
            'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 9001,
            'access_key' => $key, 'status' => 'authorized', 'idempotency_key' => fake()->uuid(),
            'storage_path' => $path, 'artifact_byte_length' => 10, 'artifact_sha256' => hash('sha256', '<nfeProc/>'), 'authorized_at' => now(),
        ]);
        $verifiedXml = $this->attachVerifiedFiscalEvidence($document);
        $key = $document->access_key;
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($document);
        $membershipId = AccountMembership::where('account_id', $office->id)->where('user_id', $mapping->approved_by_user_id)->value('id');

        return ['company_id' => $company->id, 'establishment_id' => $establishment->id, 'account_id' => $office->id,
            'delegation_id' => $delegation->id, 'client_id' => $client->id, 'mapping_id' => $mapping->id,
            'delivery_id' => $delivery->id, 'document_id' => $document->id, 'membership_id' => $membershipId, 'key' => $key, 'bytes' => $verifiedXml, 'issuer' => substr($key, 6, 14)];
    }

    private function uniqueCnpj(): string
    {
        return fake('pt_BR')->unique()->cnpj(false);
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $script = ($input['operation'] ?? '') === 'fiscal-transmit'
            ? 'tests/Integration/ErpFiscalConcurrencyWorker.php'
            : 'tests/Integration/ErpFiscalAccountingConcurrencyWorker.php';
        $process = new Process([PHP_BINARY, base_path($script)], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);

        return $process;
    }

    /** @return array<string, mixed> */
    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<int, array<string, mixed>> $inputs */
    private function concurrent(array $inputs): array
    {
        $barrier = sys_get_temp_dir().'/opmoni-erp-acct-'.bin2hex(random_bytes(8));
        $workers = array_map(function (array $input) use ($barrier): Process {
            $worker = $this->worker($input + ['barrier' => $barrier]);
            $worker->start();

            return $worker;
        }, $inputs);

        usleep(50_000);
        touch($barrier);

        try {
            return array_map(fn (Process $worker) => $this->response($worker), $workers);
        } finally {
            @unlink($barrier);
        }
    }
}
