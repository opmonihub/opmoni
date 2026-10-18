<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountContractingCertificate;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\SerproCredentialLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BusinessTestCase;

class AccountContractingCertificateApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_foreign_platform_vault_is_separate_encrypted_and_metadata_only(): void
    {
        $account = Account::factory()->create();
        $legacy = AccountCertificate::factory()->create(['account_id' => $account->id]);
        $before = $legacy->fresh()->getRawOriginal();
        [$file, $bytes] = $this->certificate('');
        $url = $this->url($account);
        $this->getJson($url)->assertOk()->assertJsonPath('data.configured', false);
        $response = $this->put($url, ['certificate' => $file, 'password' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['configured', 'document', 'subject', 'serial', 'not_before', 'not_after', 'updated_at'], array_keys($response->json('data')));
        $response->assertJsonPath('data.document', '12345678000195');
        $stored = AccountContractingCertificate::sole();
        $this->assertSame($bytes, $stored->certificateBytes());
        $this->assertSame('', $stored->certificatePassword());
        $this->assertSame(1, $stored->revision);
        $this->assertNotSame($bytes, $stored->getRawOriginal('certificate_encrypted'));
        $this->assertArrayNotHasKey('certificate_encrypted', $stored->toArray());
        $this->assertArrayNotHasKey('password_encrypted', $stored->toArray());
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_certificates', 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->getJson($url.'/download')->assertNotFound();
        $this->deleteJson($url)->assertStatus(405);
    }

    public function test_explicit_non_first_account_source_and_atomic_rotation_snapshot(): void
    {
        Account::factory()->create();
        $account = Account::factory()->create();
        [$file] = $this->certificate('  ');
        $this->put($this->url($account), ['certificate' => $file, 'password' => '  '], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/v1/serpro/connection', [
            'consumer_key' => 'key-private-value', 'consumer_secret' => 'secret-private-value',
            'certificate_source' => 'account', 'contracting_account_id' => $account->id,
        ])->assertOk()->assertJsonPath('data.certificate_source', 'account')->assertJsonPath('data.contracting_account_id', $account->id)->assertJsonPath('data.legacy_account_certificate', false);
        $connection = SerproConnection::sole();
        $this->assertNull($connection->certificate_encrypted);
        $this->assertNull($connection->certificate_password_encrypted);
        $this->assertNotSame('key-private-value', DB::table('serpro_connections')->value('consumer_key'));
        $this->assertArrayNotHasKey('consumer_key', $connection->toArray());
        $lifecycle = app(SerproCredentialLifecycle::class);
        $snapshot = $lifecycle->snapshot();
        $this->assertSame('  ', $snapshot->certificatePassword());
        $this->assertTrue($lifecycle->isCurrent($snapshot));
        foreach ([json_encode($snapshot), serialize($snapshot), print_r($snapshot, true)] as $serialized) {
            $this->assertStringNotContainsString('key-private-value', $serialized);
            $this->assertStringNotContainsString('secret-private-value', $serialized);
            $this->assertStringNotContainsString($connection->consumer_secret_encrypted, $serialized);
        }
        [$next, $nextBytes] = $this->certificate('', '27865757000102');
        $this->put($this->url($account), ['certificate' => $next, 'password' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertFalse($lifecycle->isCurrent($snapshot));
        $fresh = $lifecycle->snapshot();
        $this->assertSame($nextBytes, $fresh->certificateBytes());
        $this->assertSame('', $fresh->certificatePassword());
        $this->assertSame('27865757000102', $fresh->document);
        $this->assertSame('27865757000102', $connection->fresh()->contratante_numero);
        $this->assertSame(2, $connection->fresh()->revision);
        $this->assertSame(2, AccountContractingCertificate::sole()->revision);
        $this->assertSame('  ', $snapshot->certificatePassword());
        Queue::assertNothingPushed();
    }

    public function test_missing_password_is_distinct_from_supplied_empty_on_both_upload_routes(): void
    {
        $account = Account::factory()->create();
        [$file] = $this->certificate('');
        $this->put($this->url($account), ['certificate' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->put('/api/v1/serpro/connection', ['consumer_key' => 'key', 'consumer_secret' => 'secret', 'certificate' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->put('/api/v1/serpro/connection', ['consumer_key' => 'key', 'consumer_secret' => 'secret', 'certificate' => $file, 'password' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('', SerproConnection::sole()->certificatePassword());
        $this->assertNotNull(SerproConnection::sole()->certificate_password_encrypted);
    }

    public function test_suspension_missing_source_and_ambiguous_selectors_reject_without_mutation(): void
    {
        $account = Account::factory()->create();
        $payload = ['consumer_key' => 'key', 'consumer_secret' => 'secret', 'certificate_source' => 'account', 'contracting_account_id' => $account->id];
        $this->putJson('/api/v1/serpro/connection', $payload)->assertUnprocessable();
        [$file] = $this->certificate('pw');
        $this->put($this->url($account), ['certificate' => $file, 'password' => 'pw'], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/v1/serpro/connection', $payload + ['use_account_certificate' => true])->assertUnprocessable();
        $this->putJson('/api/v1/serpro/connection', $payload)->assertOk();
        $snapshot = app(SerproCredentialLifecycle::class)->snapshot();
        $account->update(['status' => 'suspended']);
        $this->assertFalse(app(SerproCredentialLifecycle::class)->isCurrent($snapshot));
        $this->putJson('/api/v1/serpro/connection', $payload)->assertUnprocessable();
        $this->put($this->url($account), ['certificate' => $file, 'password' => 'pw'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(1, AccountContractingCertificate::sole()->revision);
    }

    public function test_only_super_admin_may_read_or_replace_platform_vault(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->create());
        $this->getJson($this->url($account))->assertForbidden();
        [$file] = $this->certificate('pw');
        $this->put($this->url($account), ['certificate' => $file, 'password' => 'pw'], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseCount('account_contracting_certificates', 0);
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_replacement_rolls_back_source_and_connection(string $kind): void
    {
        $account = Account::factory()->create();
        [$file] = $this->certificate('pw');
        $this->put($this->url($account), ['certificate' => $file, 'password' => 'pw'], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/v1/serpro/connection', ['consumer_key' => 'key', 'consumer_secret' => 'secret', 'certificate_source' => 'account', 'contracting_account_id' => $account->id])->assertOk();
        $snapshot = app(SerproCredentialLifecycle::class)->snapshot();
        [$bad] = $this->certificate('pw', $kind === 'ambiguous' ? '12345678000195:27865757000102' : ($kind === 'unsupported' ? 'NO DOCUMENT' : '12345678000195'));
        if ($kind === 'malformed' || $kind === 'oversized') {
            file_put_contents($bad->getPathname(), $kind === 'malformed' ? 'not-pkcs12' : str_repeat('x', 2097153));
            clearstatcache(true, $bad->getPathname());
        }
        if ($kind === 'expired') {
            $this->travel(400)->days();
        }
        if ($kind === 'future') {
            $this->travel(-2)->days();
        }
        $this->put($this->url($account), ['certificate' => $bad, 'password' => $kind === 'password' ? 'wrong' : 'pw'], ['Accept' => 'application/json'])->assertStatus($kind === 'oversized' ? 413 : 422);
        $this->travelBack();
        $this->assertSame(1, AccountContractingCertificate::sole()->revision);
        $this->assertTrue(app(SerproCredentialLifecycle::class)->isCurrent($snapshot));
    }

    public function test_real_pfx_extension_and_exact_two_mib_bound(): void
    {
        $account = Account::factory()->create();
        [$file, $bytes] = $this->certificate('pw');
        $this->put($this->url($account), ['certificate' => new UploadedFile($file->getPathname(), 'contracting.pfx', null, null, true), 'password' => 'pw'], ['Accept' => 'application/json'])->assertOk();
        file_put_contents($file->getPathname(), $bytes.str_repeat("\0", 2097152 - strlen($bytes)));
        $this->put($this->url($account), ['certificate' => $file, 'password' => 'pw'], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_native_container_with_matching_key_is_valid_but_missing_or_mismatched_key_is_rejected(): void
    {
        $account = Account::factory()->create();
        [$file, $bytes] = $this->certificate('pw');
        $this->assertTrue(openssl_pkcs12_read($bytes, $parsed, 'pw'));
        $matched = $this->unprotectedPkcs12($parsed['cert'], $parsed['pkey']);
        file_put_contents($file->getPathname(), $matched);
        $this->put($this->url($account), ['certificate' => $file, 'password' => ''], ['Accept' => 'application/json'])->assertOk();
        $otherKey = openssl_pkey_new(['private_key_bits' => 1024]);
        $this->assertTrue(openssl_pkey_export($otherKey, $privatePem));
        foreach ([null, $privatePem] as $key) {
            file_put_contents($file->getPathname(), $this->unprotectedPkcs12($parsed['cert'], $key));
            $this->put($this->url($account), ['certificate' => $file, 'password' => ''], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertSame(1, AccountContractingCertificate::sole()->revision);
    }

    public function test_runtime_unsupported_legacy_cipher_is_rejected_as_a_certificate_error(): void
    {
        $account = Account::factory()->create();
        [$file, $bytes] = $this->certificate('pw');
        $this->assertTrue(openssl_pkcs12_read($bytes, $parsed, 'pw'));
        $certPath = tempnam(sys_get_temp_dir(), 'platform-cert-');
        $keyPath = tempnam(sys_get_temp_dir(), 'platform-key-');
        $this->temporaryFiles[] = $certPath;
        $this->temporaryFiles[] = $keyPath;
        file_put_contents($certPath, $parsed['cert']);
        file_put_contents($keyPath, $parsed['pkey']);
        $process = proc_open(['openssl', 'pkcs12', '-export', '-legacy', '-in', $certPath, '-inkey', $keyPath, '-passout', 'pass:pw', '-out', $file->getPathname()], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->assertSame(0, proc_close($process));
        $this->assertFalse(openssl_pkcs12_read(file_get_contents($file->getPathname()), $unsupported, 'pw'));
        $this->put($this->url($account), ['certificate' => $file, 'password' => 'pw'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $this->assertDatabaseCount('account_contracting_certificates', 0);
    }

    public function test_legacy_source_snapshot_tracks_replacement_and_removal_without_migrating_tenant_material(): void
    {
        $account = Account::factory()->create();
        $legacy = AccountCertificate::factory()->create(['account_id' => $account->id]);
        $this->putJson('/api/v1/serpro/connection', ['consumer_key' => 'key', 'consumer_secret' => 'secret', 'use_account_certificate' => true])->assertOk()->assertJsonPath('data.legacy_account_certificate', true);
        $lifecycle = app(SerproCredentialLifecycle::class);
        $first = $lifecycle->snapshot();
        $this->assertSame('legacy_account', $first->source);
        $this->assertSame('pfx-de-descarte', $first->certificateBytes());
        $this->assertSame($legacy->id, $first->sourceId);
        $legacy->forceFill(['replaced_at' => now(), 'certificate_encrypted' => null, 'password_encrypted' => null])->save();
        $next = AccountCertificate::factory()->create(['account_id' => $account->id, 'document' => '27865757000102']);
        $this->assertFalse($lifecycle->isCurrent($first));
        $second = $lifecycle->snapshot();
        $this->assertSame($next->id, $second->sourceId);
        $this->assertSame('27865757000102', $second->document);
        $next->forceFill(['removed_at' => now(), 'certificate_encrypted' => null, 'password_encrypted' => null])->save();
        $this->assertFalse($lifecycle->isCurrent($second));
        $this->assertFalse($lifecycle->snapshot()->hasUsableCertificate());
        $this->assertDatabaseCount('account_contracting_certificates', 0);
    }

    public function test_explicit_upload_detaches_account_source_and_blank_credentials_preserve_generation(): void
    {
        $account = Account::factory()->create();
        [$file] = $this->certificate('pw');
        $this->put($this->url($account), ['certificate' => $file, 'password' => 'pw'], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/v1/serpro/connection', ['consumer_key' => 'key', 'consumer_secret' => 'secret', 'certificate_source' => 'account', 'contracting_account_id' => $account->id])->assertOk();
        $lifecycle = app(SerproCredentialLifecycle::class);
        $before = $lifecycle->snapshot();
        $this->putJson('/api/v1/serpro/connection', ['consumer_key' => '', 'consumer_secret' => ''])->assertOk();
        $this->assertTrue($lifecycle->isCurrent($before));
        $this->putJson('/api/v1/serpro/connection', ['certificate_source' => 'upload'])->assertUnprocessable();
        [$upload, $bytes] = $this->certificate('');
        $this->put('/api/v1/serpro/connection', ['certificate_source' => 'upload', 'certificate' => $upload, 'password' => ''], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.certificate_source', 'upload')->assertJsonPath('data.contracting_account_id', null);
        $this->assertFalse($lifecycle->isCurrent($before));
        $connection = SerproConnection::sole();
        $this->assertNull($connection->account_contracting_certificate_id);
        $this->assertSame($bytes, $connection->certificateBytes());
        $this->assertSame('', $connection->certificatePassword());
        $this->assertSame('key', $connection->consumer_key);
        $this->assertSame('secret', $connection->consumerSecret());
    }

    /** Build unencrypted RFC 7292 bags so OpenSSL can inspect deliberately mismatched material. */
    private function unprotectedPkcs12(string $certificate, ?string $key): string
    {
        $der = fn (string $pem): string => base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
        $oid = fn (string $hex): string => hex2bin($hex);
        $certBag = $this->asn1(0x30, $oid('060b2a864886f70d010c0a0103').$this->asn1(0xA0,
            $this->asn1(0x30, $oid('060a2a864886f70d01091601').$this->asn1(0xA0, $this->asn1(0x04, $der($certificate))))));
        $keyBag = $key === null ? '' : $this->asn1(0x30, $oid('060b2a864886f70d010c0a0101').$this->asn1(0xA0, $der($key)));
        $data = fn (string $bytes): string => $this->asn1(0x30, $oid('06092a864886f70d010701').$this->asn1(0xA0, $this->asn1(0x04, $bytes)));

        return $this->asn1(0x30, "\x02\x01\x03".$data($this->asn1(0x30, $data($this->asn1(0x30, $keyBag.$certBag)))));
    }

    private function asn1(int $tag, string $content): string
    {
        $length = strlen($content);
        $encoded = $length < 128 ? chr($length) : "\x82".pack('n', $length);

        return chr($tag).$encoded.$content;
    }

    public static function invalidUploads(): array
    {
        return array_map(fn ($kind) => [$kind], ['password', 'malformed', 'oversized', 'ambiguous', 'unsupported', 'expired', 'future']);
    }

    private function url(Account $account): string
    {
        return '/api/v1/admin/accounts/'.$account->id.'/contracting-certificate';
    }

    private function certificate(string $password, string $document = '12345678000195'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'Platform:'.$document], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertTrue(openssl_pkcs12_export($certificate, $bytes, $key, $password));
        $path = tempnam(sys_get_temp_dir(), 'platform-p12-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $bytes);

        return [new UploadedFile($path, 'contracting.p12', 'application/octet-stream', null, true), $bytes];
    }
}
