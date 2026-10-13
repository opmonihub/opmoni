<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\ClientCertificateVault;
use App\Services\ClientManager;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ClientCertificateTransactionTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function beforeTruncatingDatabase(): void
    {
        // Each test owns an in-memory database without a wrapping transaction.
        RefreshDatabaseState::$migrated = false;
    }

    public function test_outer_rollback_preserves_previous_certificate_and_cleans_new_blob(): void
    {
        [$client, $old] = $this->clientWithCertificate();
        $oldPath = $old->storage_path;
        $oldBytes = Storage::disk('certificates')->get($oldPath);
        DB::beginTransaction();
        $new = app(ClientCertificateVault::class)->replace($client, $this->upload($old), 'secret');
        DB::rollBack();

        $this->assertNull($old->refresh()->replaced_at);
        $this->assertTrue(Storage::disk('certificates')->exists($oldPath));
        $this->assertSame($oldBytes, Storage::disk('certificates')->get($oldPath));
        $this->assertFalse(Storage::disk('certificates')->exists($new->storage_path));
        $this->assertDatabaseCount('client_certificates', 1);
    }

    public function test_old_blob_is_deleted_only_after_outer_commit(): void
    {
        [$client, $old] = $this->clientWithCertificate();
        $oldPath = $old->storage_path;
        DB::beginTransaction();
        $new = app(ClientCertificateVault::class)->replace($client, $this->upload($old), 'secret');
        $existsBeforeCommit = Storage::disk('certificates')->exists($oldPath);
        DB::commit();

        $this->assertTrue($existsBeforeCommit);
        $this->assertFalse(Storage::disk('certificates')->exists($oldPath));
        $this->assertTrue(Storage::disk('certificates')->exists($new->storage_path));
    }

    public function test_rollback_of_parent_savepoint_cleans_replacement_blob(): void
    {
        [$client, $old] = $this->clientWithCertificate();
        $oldPath = $old->storage_path;
        DB::beginTransaction();
        DB::beginTransaction();
        $new = app(ClientCertificateVault::class)->replace($client, $this->upload($old), 'secret');
        DB::rollBack();
        DB::commit();

        $this->assertTrue(Storage::disk('certificates')->exists($oldPath));
        $this->assertFalse(Storage::disk('certificates')->exists($new->storage_path));
        $this->assertNull($old->refresh()->replaced_at);
    }

    public function test_remove_preserves_blob_when_outer_transaction_rolls_back(): void
    {
        [$client, $old] = $this->clientWithCertificate();
        $oldPath = $old->storage_path;
        DB::beginTransaction();
        app(ClientCertificateVault::class)->remove($client);
        DB::rollBack();

        $this->assertNull($old->refresh()->removed_at);
        $this->assertTrue(Storage::disk('certificates')->exists($oldPath));
    }

    public function test_client_delete_preserves_certificate_when_outer_transaction_rolls_back(): void
    {
        [$client, $old] = $this->clientWithCertificate();
        $oldPath = $old->storage_path;
        DB::beginTransaction();
        app(ClientManager::class)->delete($client);
        DB::rollBack();

        $this->assertFalse($client->refresh()->trashed());
        $this->assertNull($old->refresh()->removed_at);
        $this->assertTrue(Storage::disk('certificates')->exists($oldPath));
    }

    public function test_after_commit_delete_failure_keeps_committed_replacement_blob(): void
    {
        [$client, $old] = $this->clientWithCertificate();
        $oldPath = $old->storage_path;
        $real = Storage::disk('certificates');
        $disk = \Mockery::mock($real)->makePartial();
        $disk->shouldReceive('delete')->with($oldPath)->andThrow(new RuntimeException('delete failed'));
        Storage::partialMock()->shouldReceive('disk')->with('certificates')->andReturn($disk);
        DB::beginTransaction();
        try {
            $new = app(ClientCertificateVault::class)->replace($client, $this->upload($old), 'secret');
            DB::commit();
            $this->fail('Expected the old blob deletion failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('delete failed', $exception->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertNotNull($old->refresh()->replaced_at);
        $this->assertTrue($real->exists($new->storage_path));
        $this->assertSame($new->getKey(), $client->refresh()->currentCertificate->getKey());
    }

    public function test_certificate_with_long_subject_and_multibyte_filename_remains_usable(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $config = ['config' => '/etc/ssl/openssl.cnf', 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new(['private_key_bits' => 1024, ...$config]);
        $csr = openssl_csr_new([
            'countryName' => 'BR', 'stateOrProvinceName' => str_repeat('S', 60),
            'localityName' => str_repeat('L', 60), 'organizationName' => str_repeat('O', 60),
            'organizationalUnitName' => str_repeat('U', 60), 'commonName' => str_repeat('C', 60),
        ], $key, $config);
        $cert = openssl_csr_sign($csr, null, $key, 365, $config);
        $bytes = '';
        $this->assertTrue(openssl_pkcs12_export($cert, $bytes, $key, 'secret'));
        $this->assertGreaterThan(255, mb_strlen(openssl_x509_parse($cert)['name']));
        $file = UploadedFile::fake()->createWithContent(str_repeat('á', 260).'.pfx', $bytes);
        $record = app(ClientCertificateVault::class)->replace($client, $file, 'secret');

        $this->assertLessThanOrEqual(255, mb_strlen($record->subject));
        $this->assertSame(str_repeat('á', 255), $record->original_filename);
        $this->assertSame($bytes, base64_decode(Crypt::decryptString(Storage::disk('certificates')->get($record->storage_path))));
        $this->assertSame(hash('sha256', $bytes), $record->sha256);
    }

    /** @return array{Client, ClientCertificate} */
    private function clientWithCertificate(): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $certificate = ClientCertificate::factory()->withPassword('secret')->create(['client_id' => $client->getKey(), 'account_id' => $account->getKey()]);

        return [$client, $certificate];
    }

    private function upload(ClientCertificate $certificate): UploadedFile
    {
        $bytes = base64_decode(Crypt::decryptString(Storage::disk('certificates')->get($certificate->storage_path)));

        return UploadedFile::fake()->createWithContent('replacement.pfx', $bytes);
    }
}
