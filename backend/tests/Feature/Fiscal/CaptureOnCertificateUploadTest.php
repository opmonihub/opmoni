<?php

namespace Tests\Feature\Fiscal;

use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\SupportAccessLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O certificado do cliente que acorda a captura: o upload bem-sucedido
 * dispara os conectores capturáveis do cliente com o `account_id` explícito,
 * e o `meta.capture` da resposta conta o que aconteceu — sem senha, sem
 * caminho, sem XML.
 */
class CaptureOnCertificateUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        Storage::fake('certificates');
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_upload_despacha_a_fonte_habilitada_com_account_id(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $response = $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->post("/api/clients/{$client->getKey()}/certificate", [
                'password' => 'senha-do-certificado',
                'certificate' => $this->pfxUpload('cliente.pfx', 'senha-do-certificado'),
            ], ['Accept' => 'application/json']);

        // A resposta é o `ClientResource` de sempre e o `meta.capture` conta o
        // que a captura fez: `queued`, e a fonte que a instalação captura.
        $response->assertOk()
            ->assertJsonPath('meta.capture.status', 'queued')
            ->assertJsonPath('meta.capture.sources', ['nfe_distribuicao'])
            ->assertJsonMissingPath('data.certificate.password_encrypted')
            ->assertJsonMissingPath('data.certificate.storage_path');

        // O job carrega o `account_id` do cliente — gravado aqui porque o
        // `BelongsToAccount` não filtra nada numa fila sem tenant.
        Queue::assertPushed(CaptureFiscalDocumentsJob::class, 1);
        Queue::assertPushed(
            CaptureFiscalDocumentsJob::class,
            fn (CaptureFiscalDocumentsJob $job): bool => $job->accountId === $account->getKey()
                && $job->clientId === $client->getKey(),
        );
    }

    public function test_recusa_de_validacao_nao_despacha_e_nao_toca_o_cofre(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        // Senha que não abre o PKCS#12: a captura só nasce do certificado que
        // o cofre aceitou, e um 422 não pode deixar job na fila.
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->post("/api/clients/{$client->getKey()}/certificate", [
                'password' => 'senha-errada',
                'certificate' => $this->pfxUpload('cliente.pfx', 'senha-do-certificado'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        Queue::assertNotPushed(CaptureFiscalDocumentsJob::class);
        $this->assertDatabaseCount('client_certificates', 0);
    }

    public function test_fonte_bloqueada_responde_blocked_e_nao_despacha(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $cursor = new FiscalCursor;
        $cursor->forceFill([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => 'nfe_distribuicao',
            'blocked_until' => CarbonImmutable::parse('2026-09-30'),
        ])->save();

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->post("/api/clients/{$client->getKey()}/certificate", [
                'password' => 'senha-do-certificado',
                'certificate' => $this->pfxUpload('cliente.pfx', 'senha-do-certificado'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('meta.capture.status', 'blocked')
            ->assertJsonPath('meta.capture.sources', [])
            ->assertJsonPath('meta.capture.blocked_until', '2026-09-30T00:00:00.000000Z')
            ->assertJsonPath('meta.capture.reason', 'capture_blocked');

        Queue::assertNotPushed(CaptureFiscalDocumentsJob::class);
    }

    public function test_certificado_que_o_proprio_upload_gravou_nao_pode_ser_lido_como_velho(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'valid_until' => CarbonImmutable::parse('2026-09-27'),
            'password_encrypted' => null,
        ]);

        // A decisão do dispatcher é tomada depois do `replace`: a captura
        // enfileirada precisa estar autorizada pelo certificado novo, não pelo
        // que estava lá antes — e é por isso que o upload de um certificado
        // sem senha guardada não despacha nada (o que entrou não serve para
        // consultar).
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->post("/api/clients/{$client->getKey()}/certificate", [
                'password' => 'senha-do-certificado',
                'certificate' => $this->pfxUpload('cliente.pfx', 'senha-do-certificado'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('meta.capture.status', 'queued');

        $certificado = ClientCertificate::query()
            ->where('client_id', $client->getKey())
            ->whereNull('replaced_at')
            ->sole();
        $this->assertSame($account->getKey(), $certificado->account_id);
        $this->assertNotNull($certificado->password_encrypted);
    }

    public function test_user_nao_pode_enviar_certificado_e_nada_e_enfileirado(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->post("/api/clients/{$client->getKey()}/certificate", [
                'password' => 'senha-do-certificado',
                'certificate' => $this->pfxUpload('cliente.pfx', 'senha-do-certificado'),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        Queue::assertNotPushed(CaptureFiscalDocumentsJob::class);
    }

    public function test_upload_vencido_guarda_certificado_sem_enfileirar_captura(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $file = $this->pfxUpload('cliente.pfx', 'senha-do-certificado');
        $parts = [];
        $this->assertTrue(openssl_pkcs12_read($file->get(), $parts, 'senha-do-certificado'));
        $certificate = openssl_x509_parse($parts['cert']);
        $this->assertNotFalse($certificate);
        $this->travelTo(CarbonImmutable::createFromTimestamp($certificate['validTo_time_t'])->addDay());

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->post("/api/clients/{$client->getKey()}/certificate", [
                'password' => 'senha-do-certificado',
                'certificate' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('meta.capture.status', 'not_capturable')
            ->assertJsonPath('meta.capture.sources', [])
            ->assertJsonPath('meta.capture.reason', 'certificate_expired');

        $this->assertDatabaseHas('client_certificates', ['client_id' => $client->getKey()]);
        Queue::assertNotPushed(CaptureFiscalDocumentsJob::class);
    }

    public function test_upload_e_desligamento_em_suporte_gravam_logwrite(): void
    {
        $suporte = User::factory()->create(['is_super_admin' => true]);
        $casa = Account::factory()->create();
        AccountUser::create(['account_id' => $casa->getKey(), 'user_id' => $suporte->getKey(), 'role' => 'admin']);
        $suporte->forceFill(['current_account_id' => $casa->getKey()])->save();

        $alvo = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $alvo->getKey()]);

        $this->actingAs($suporte->refresh(), 'sanctum')
            ->postJson("/api/support/accounts/{$alvo->getKey()}/enter")
            ->assertOk();

        $this->post("/api/clients/{$client->getKey()}/certificate", [
            'password' => 'senha-do-certificado',
            'certificate' => $this->pfxUpload('cliente.pfx', 'senha-do-certificado'),
        ], ['Accept' => 'application/json'])->assertOk();

        // O upload grava a fonte enfileirada; a remoção registra o que existia.
        $log = SupportAccessLog::query()
            ->where('super_admin_user_id', $suporte->getKey())
            ->where('account_id', $alvo->getKey())
            ->where('action', 'certificate')
            ->sole();

        $this->assertSame('clients', $log->metadata['resource']);
        $this->assertSame($client->getKey(), $log->metadata['resource_id']);
        $this->assertSame(['nfe_distribuicao'], $log->metadata['sources'] ?? null);
        $this->assertStringNotContainsString('senha-do-certificado', json_encode($log->metadata));
        $certificateId = $client->fresh(['currentCertificate'])->currentCertificate->getKey();

        $this->deleteJson("/api/clients/{$client->getKey()}/certificate")->assertNoContent();

        $this->assertSame(
            1,
            SupportAccessLog::query()->where('action', 'certificate-remove')->count(),
        );
        $removal = SupportAccessLog::query()->where('action', 'certificate-remove')->sole();
        $this->assertSame([
            'resource' => 'clients',
            'resource_id' => $client->getKey(),
            'certificate_id' => $certificateId,
        ], $removal->metadata);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    /**
     * O mesmo PFX de `ClientCertificateTest`: assinatura própria, gerado em
     * runtime com a senha que o request vai declarar.
     */
    private function pfxUpload(string $name, string $password): UploadedFile
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(['CN' => 'Teste'], $key, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, $password));

        return UploadedFile::fake()->createWithContent($name, $pfx);
    }
}
