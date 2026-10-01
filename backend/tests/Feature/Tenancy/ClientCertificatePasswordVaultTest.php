<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientCertificatePasswordVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
        // O upload enfileira a captura: a fila é falsa aqui porque o job que
        // roda de verdade é o que fala com o fisco.
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_upload_stores_password_usable_later(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        ['file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');

        $response = $this->post("/api/clients/{$client->getKey()}/certificate", [
            'certificate' => $file,
            'password' => 'secret',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonMissingPath('data.certificate.password_encrypted');

        $this->assertStringNotContainsString('secret', $response->getContent());

        $certificate = ClientCertificate::sole();
        $this->assertSame('secret', $certificate->certificatePassword());

        // A coluna guarda apenas o texto cifrado: a senha em claro nunca é persistida.
        $stored = $this->storedCiphertext($certificate->getKey());
        $this->assertNotNull($stored);
        $this->assertNotSame('secret', $stored);
        $this->assertSame('secret', Crypt::decryptString($stored));
    }

    public function test_replacing_certificate_drops_previous_password(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        ['file' => $first] = $this->pfxUpload('a.pfx', 'primeira');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $first, 'password' => 'primeira'], ['Accept' => 'application/json'])
            ->assertOk();

        $previous = ClientCertificate::sole();

        ['file' => $second] = $this->pfxUpload('b.pfx', 'segunda');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $second, 'password' => 'segunda'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNull($previous->refresh()->certificatePassword());
        $this->assertNull($this->storedCiphertext($previous->getKey()));

        $current = ClientCertificate::whereNull('replaced_at')->whereNull('removed_at')->sole();
        $this->assertSame('segunda', $current->certificatePassword());

        $stored = $this->storedCiphertext($current->getKey());
        $this->assertNotNull($stored);
        $this->assertNotSame('segunda', $stored);
        $this->assertSame('segunda', Crypt::decryptString($stored));
    }

    public function test_removing_certificate_drops_password(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        ['file' => $file] = $this->pfxUpload('a.pfx', 'secret');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $file, 'password' => 'secret'], ['Accept' => 'application/json'])
            ->assertOk();

        $certificate = ClientCertificate::sole();

        $this->delete("/api/clients/{$client->getKey()}/certificate", [], ['Accept' => 'application/json'])->assertNoContent();

        $this->assertNull($certificate->refresh()->certificatePassword());
        $this->assertNull($this->storedCiphertext($certificate->getKey()));
    }

    /**
     * A senha não aparece em nenhum registro de log, nem na mensagem nem no
     * contexto. O ouvinte pega tudo o que passa pelo logger, então um canal
     * novo ou um `Log::debug` esquecido cai aqui do mesmo jeito.
     */
    public function test_password_never_reaches_the_log(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        ['file' => $wrong] = $this->pfxUpload('errada.pfx', 'senhacerta-um');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $wrong, 'password' => 'senhaerrada-zz'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        ['file' => $first] = $this->pfxUpload('a.pfx', 'senhacerta-um');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $first, 'password' => 'senhacerta-um'], ['Accept' => 'application/json'])
            ->assertOk();

        ['file' => $second] = $this->pfxUpload('b.pfx', 'senhacerta-dois');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $second, 'password' => 'senhacerta-dois'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->delete("/api/clients/{$client->getKey()}/certificate", [], ['Accept' => 'application/json'])->assertNoContent();

        foreach ($logged as $line) {
            foreach (['senhacerta-um', 'senhacerta-dois', 'senhaerrada-zz'] as $password) {
                $this->assertStringNotContainsString($password, $line);
            }
        }
    }

    /**
     * Lê a coluna pelo query builder, sem passar pelo accessor do modelo.
     */
    private function storedCiphertext(int $certificateId): ?string
    {
        $value = DB::table('client_certificates')->where('id', $certificateId)->value('password_encrypted');

        return is_string($value) ? $value : null;
    }

    /** @return array{file: UploadedFile} */
    private function pfxUpload(string $name, string $password): array
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];
        $key = openssl_pkey_new(array_merge(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA], $config));
        $csr = openssl_csr_new(['CN' => 'Teste'], $key, array_merge(['digest_alg' => 'sha256'], $config));
        $cert = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($cert, $pfx, $key, $password));

        return ['file' => UploadedFile::fake()->createWithContent($name, $pfx)];
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
