<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientCertificatePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
    }

    public function test_returns_decrypted_password(): void
    {
        $certificate = ClientCertificate::factory()->create([
            'password_encrypted' => Crypt::encryptString('segredo'),
        ]);

        $this->assertSame('segredo', $certificate->certificatePassword());
    }

    public function test_returns_null_when_password_absent(): void
    {
        $certificate = ClientCertificate::factory()->create(['password_encrypted' => null]);

        $this->assertNull($certificate->certificatePassword());
    }

    public function test_password_is_not_serialized(): void
    {
        $certificate = ClientCertificate::factory()->create([
            'password_encrypted' => Crypt::encryptString('segredo'),
        ]);

        $this->assertArrayNotHasKey('password_encrypted', $certificate->toArray());
    }

    public function test_column_holds_ciphertext_not_the_plain_password(): void
    {
        $certificate = ClientCertificate::factory()->withPassword('segredo')->create();

        $this->assertNotSame('segredo', $certificate->password_encrypted);
        $this->assertSame('segredo', Crypt::decryptString($certificate->password_encrypted));
    }

    public function test_with_password_writes_a_readable_pkcs12_to_the_certificates_disk(): void
    {
        $certificate = ClientCertificate::factory()->withPassword('segredo')->create();

        $this->assertNotNull($certificate->storage_path);
        $this->assertTrue(Storage::disk('certificates')->exists($certificate->storage_path));

        $contents = Storage::disk('certificates')->get($certificate->storage_path);
        $this->assertSame(hash('sha256', base64_decode(Crypt::decryptString($contents))), $certificate->sha256);

        $parsed = [];
        $this->assertTrue(openssl_pkcs12_read(base64_decode(Crypt::decryptString($contents)), $parsed, 'segredo'));
        $this->assertNotEmpty($parsed['cert'] ?? null);
    }

    public function test_without_password_leaves_no_password_and_no_stored_file(): void
    {
        $certificate = ClientCertificate::factory()->withoutPassword()->create();

        $this->assertNull($certificate->certificatePassword());
        $this->assertNull($certificate->storage_path);
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    public function test_states_stay_composable_with_overridden_keys(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        $certificate = ClientCertificate::factory()
            ->withPassword()
            ->create(['account_id' => $account->getKey(), 'client_id' => $client->getKey()]);

        $this->assertSame($account->getKey(), $certificate->account_id);
        $this->assertSame($client->getKey(), $certificate->client_id);
        $this->assertSame('senha', $certificate->certificatePassword());
        $this->assertStringStartsWith(sprintf('%d/%d/', $account->getKey(), $client->getKey()), $certificate->storage_path);
    }
}
