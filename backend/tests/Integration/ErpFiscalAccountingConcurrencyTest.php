<?php

namespace Tests\Integration;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpFiscalAccountingConcurrencyTest extends TestCase
{
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
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $office = Account::factory()->create();
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
        ]);
        Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => $company->cnpj,
        ]);

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
        $deliveryId = (int) DB::table('erp_accounting_deliveries')->insertGetId([
            'erp_company_id' => $company->id,
            'erp_fiscal_document_id' => $documentId,
            'account_id' => $office->id,
            'status' => ErpAccountingDelivery::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $results = $this->concurrent([
            ['operation' => 'deliver', 'delivery_id' => $deliveryId],
            ['operation' => 'deliver', 'delivery_id' => $deliveryId],
        ]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertGreaterThanOrEqual(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));

        $this->assertSame(1, ErpAccountingDelivery::query()->whereKey($deliveryId)->where('status', ErpAccountingDelivery::STATUS_DELIVERED)->count());
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->where('chave_acesso', '35251030288513000100550010000091001234567890')->count());
    }

    private function assertConcurrentTransmitAuthorizesOnce(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj(), 'operating_settings' => ['uf' => 'SP']]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $documentId = (int) DB::table('erp_fiscal_documents')->insertGetId([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => random_int(30000, 39999),
            'access_key' => '35251030288513000100550010000090011234567890',
            'status' => ErpFiscalDocumentStateMachine::QUEUED,
            'idempotency_key' => 'fiscal-transmit-race-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $results = $this->concurrent([
            ['operation' => 'fiscal-transmit', 'document_id' => $documentId],
            ['operation' => 'fiscal-transmit', 'document_id' => $documentId],
        ]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertGreaterThanOrEqual(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(
            ErpFiscalDocumentStateMachine::AUTHORIZED,
            ErpFiscalDocument::query()->findOrFail($documentId)->status,
        );
    }

    private function assertDeliveryBlockedWhenDelegationRevoked(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => $this->uniqueCnpj()]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
        ]);
        Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => $company->cnpj,
        ]);
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
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($document);
        $this->assertNotNull($delivery);
        $delegation->update(['status' => 'revoked']);

        $worker = $this->worker(['operation' => 'deliver', 'delivery_id' => $delivery->id]);
        $worker->start();
        $result = $this->response($worker);
        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(ErpAccountingDelivery::STATUS_BLOCKED, ErpAccountingDelivery::query()->findOrFail($delivery->id)->status);
    }

    private function uniqueCnpj(): string
    {
        return str_pad((string) random_int(1_000_000_000_000_0, 9_999_999_999_999_9), 14, '0', STR_PAD_LEFT);
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
