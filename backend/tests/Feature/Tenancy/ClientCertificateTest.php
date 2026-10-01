<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\SerproClientAuthorization;
use App\Models\User;
use App\Services\ClientCertificateVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
        Queue::fake([CaptureFiscalDocumentsJob::class]);
        Http::preventStrayRequests();
    }

    public function test_operador_uploads_valid_certificate_encrypted(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        ['bytes' => $bytes, 'file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');

        $response = $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'secret'],
            ['Accept' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonMissingPath('data.certificate.storage_path')
            ->assertJsonMissingPath('data.certificate.password');

        $record = ClientCertificate::sole();
        $stored = Storage::disk('certificates')->get($record->storage_path);
        $this->assertNotSame($bytes, $stored);
        $this->assertSame(hash('sha256', $bytes), $record->sha256);
        $this->assertNotNull($record->subject);
    }

    public function test_upload_responde_com_o_resumo_da_procuracao(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00006',
            'code' => '00006',
            'expires_on' => today()->addYear()->toDateString(),
        ]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        ['file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');

        $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'secret'],
            ['Accept' => 'application/json']
        )->assertOk()
            ->assertJsonPath('data.ecac_power_of_attorney.status', 'valid')
            ->assertJsonPath('data.ecac_power_of_attorney_status', 'valid');
    }

    public function test_wrong_password_returns_422_without_record_or_file(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        ['file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');

        $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'wrong'],
            ['Accept' => 'application/json']
        )->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('client_certificates', 0);
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    public function test_invalid_extension_returns_422(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $file = UploadedFile::fake()->createWithContent('cliente.txt', 'not-a-cert');

        $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'secret'],
            ['Accept' => 'application/json']
        )->assertUnprocessable()->assertJsonValidationErrors('certificate');

        $this->assertDatabaseCount('client_certificates', 0);
    }

    public function test_oversize_certificate_returns_422(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $file = UploadedFile::fake()->createWithContent('cliente.pfx', str_repeat('a', 2 * 1024 * 1024 + 1));

        $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'secret'],
            ['Accept' => 'application/json']
        )->assertUnprocessable()->assertJsonValidationErrors('certificate');
    }

    public function test_user_role_cannot_upload_certificate(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'user'), 'sanctum');

        ['file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');

        $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'secret'],
            ['Accept' => 'application/json']
        )->assertForbidden();
    }

    public function test_cross_account_certificate_is_404(): void
    {
        $client = Client::factory()->individual()->create();
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        ['file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');

        $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'secret'],
            ['Accept' => 'application/json']
        )->assertNotFound();

        $this->deleteJson("/api/clients/{$client->getKey()}/certificate")->assertNotFound();
    }

    public function test_replace_removes_old_ciphertext(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        ['file' => $first] = $this->pfxUpload('primeiro.pfx', 'secret');
        $this->post("/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $first, 'password' => 'secret'],
            ['Accept' => 'application/json'])->assertOk();

        $old = ClientCertificate::sole();
        $oldPath = $old->storage_path;
        $this->assertTrue(Storage::disk('certificates')->exists($oldPath));

        ['file' => $second] = $this->pfxUpload('segundo.pfx', 'secret');
        $this->post("/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $second, 'password' => 'secret'],
            ['Accept' => 'application/json'])->assertOk();

        $this->assertFalse(Storage::disk('certificates')->exists($oldPath));
        $this->assertSame(1, ClientCertificate::whereNull('replaced_at')->whereNull('removed_at')->count());
        $this->assertNotNull($old->refresh()->replaced_at);
        $this->assertNull($old->refresh()->storage_path);
    }

    public function test_failed_replace_preserves_current_certificate(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        ['file' => $first] = $this->pfxUpload('primeiro.pfx', 'secret');
        $this->post("/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $first, 'password' => 'secret'],
            ['Accept' => 'application/json'])->assertOk();

        $current = ClientCertificate::sole();
        $currentPath = $current->storage_path;
        $storedBefore = Storage::disk('certificates')->get($currentPath);

        ['file' => $bad] = $this->pfxUpload('outro.pfx', 'secret');
        $this->post("/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $bad, 'password' => 'wrong'],
            ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertTrue(Storage::disk('certificates')->exists($currentPath));
        $this->assertSame($storedBefore, Storage::disk('certificates')->get($currentPath));
        $this->assertNull($current->refresh()->replaced_at);
        $this->assertSame(1, ClientCertificate::whereNull('replaced_at')->whereNull('removed_at')->count());
    }

    public function test_remove_cleans_file_and_marks_record(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        ['file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');
        $this->post("/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $file, 'password' => 'secret'],
            ['Accept' => 'application/json'])->assertOk();

        $record = ClientCertificate::sole();
        $path = $record->storage_path;

        $this->deleteJson("/api/clients/{$client->getKey()}/certificate")->assertNoContent();

        $this->assertFalse(Storage::disk('certificates')->exists($path));
        $this->assertNotNull($record->refresh()->removed_at);
        $this->assertSame(0, ClientCertificate::whereNull('replaced_at')->whereNull('removed_at')->count());
    }

    public function test_old_file_delete_failure_preserves_new_certificate(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $vault = $this->app->make(ClientCertificateVault::class);

        ['file' => $first] = $this->pfxUpload('primeiro.pfx', 'secret');
        $vault->replace($client, $first, 'secret');

        $old = ClientCertificate::whereNull('replaced_at')->whereNull('removed_at')->sole();
        $oldPath = $old->storage_path;

        $real = Storage::disk('certificates');
        $disk = \Mockery::mock($real)->makePartial();
        $disk->shouldReceive('delete')->andReturnUsing(function ($paths) use ($real, $oldPath) {
            foreach ((array) $paths as $candidate) {
                if ($candidate === $oldPath) {
                    throw new \RuntimeException('old delete boom');
                }
            }

            return $real->delete($paths);
        });
        Storage::partialMock()->shouldReceive('disk')->with('certificates')->andReturn($disk);

        ['file' => $second] = $this->pfxUpload('segundo.pfx', 'secret');

        try {
            $vault->replace($client->refresh(), $second, 'secret');
            $this->fail('Expected the old-file delete failure to bubble up.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('old delete boom', $exception->getMessage());
        }

        $new = ClientCertificate::whereNull('replaced_at')->whereNull('removed_at')->sole();
        $this->assertNotSame($oldPath, $new->storage_path);
        $this->assertTrue($real->exists($new->storage_path));
        $this->assertNotNull($old->refresh()->replaced_at);
    }

    /**
     * @return array{bytes: string, file: UploadedFile}
     */
    private function pfxUpload(string $name, string $password): array
    {
        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $this->opensslConfig()
        ));
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['CN' => 'Teste'], $key, array_merge(['digest_alg' => 'sha256'], $this->opensslConfig()));
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $this->opensslConfig()));
        $this->assertNotFalse($cert);
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($cert, $pfx, $key, $password));

        return ['bytes' => $pfx, 'file' => UploadedFile::fake()->createWithContent($name, $pfx)];
    }

    /**
     * @return array{config?: string}
     */
    private function opensslConfig(): array
    {
        return file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
