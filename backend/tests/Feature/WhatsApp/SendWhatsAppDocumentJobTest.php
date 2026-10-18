<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\SerproAuthorizationTermState;
use App\Jobs\SendWhatsAppDocumentJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproConnection;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\BusinessTestCase;

/**
 * O job de envio: lê os bytes do artefato pelo mesmo serviço do download
 * PGDAS (`SerproPgdasDocumentReader` real, com o Integra Contador falso
 * em `Http::fake`), manda multipart ao wzap falso e transiciona a linha
 * `queued` → `sent`/`failed`.
 */
class SendWhatsAppDocumentJobTest extends BusinessTestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private WhatsAppDispatch $dispatch;

    private ClientDispatchContact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;

        config()->set('whatsapp.wzap.base_url', 'http://wzap.test');
        config()->set('integra-contador.gateway_url', 'http://integra.test/integra-contador/v1');

        $this->account = $this->contaPronta();
        $this->client = Client::factory()->company()->create([
            'account_id' => $this->account->getKey(),
            'tax_id' => '33683111000875',
            'phone' => '5511987654321',
        ]);

        WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-1',
        ]);

        SerproMonitoring::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'periods' => [[
                'period' => '2026-01',
                'declared_at' => '2026-01-31T10:00:00Z',
                'declaration_number' => '00000000202601001',
                'rectified' => false,
                'slip_number' => '07202215764027873',
                'slip_issued_at' => '2026-02-01T09:00:00Z',
                'due_on' => null,
                'slip_paid' => true,
            ]],
        ]);

        $this->contact = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'value' => '5511987654321',
        ]);

        $this->dispatch = WhatsAppDispatch::factory()->withLogicalKey()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'client_dispatch_contact_id' => $this->contact->getKey(),
            'artifact_type' => 'recibo',
            'competence' => '2026-01',
            'retry_safe' => true,
        ]);

        // O token do Integra Contador já vale: o job não paga autenticação.
        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_envio_bem_sucedido_transiciona_para_sent_com_message_id(): void
    {
        $pdf = '%PDF-1.4 conteudo-do-recibo';

        Http::fake([
            'wzap.test/instances/inst-1/messages/media' => Http::response(['data' => [
                'message_id' => 'wamid.envio-1',
                'status' => 'sent',
            ]], 202),
            'integra.test/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => json_encode(['recibo' => ['pdf' => base64_encode($pdf)]]),
                'mensagens' => [['codigo' => '00', 'texto' => 'OK']],
                'responseId' => 'resp-15',
            ]),
        ]);

        $this->handleJob();

        $dispatch = $this->dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame('wamid.envio-1', $dispatch->message_id);
        $this->assertNull($dispatch->failure_reason);

        // O multipart vai com a apikey de instância, a chave de
        // idempotência do disparo, o telefone do cliente e os bytes que o
        // leitor PGDAS devolveu.
        Http::assertSent(function ($request) use ($pdf): bool {
            if ($request->method() !== 'POST'
                || $request->url() !== 'http://wzap.test/instances/inst-1/messages/media') {
                return false;
            }

            $partes = collect($request->data())->keyBy('name');

            return ($request->header('apikey')[0] ?? null) === 'chave-instancia'
                && ($request->header('Idempotency-Key')[0] ?? null) === $this->dispatch->idempotency_key
                && ($partes['to']['contents'] ?? null) === '5511987654321'
                && ($partes['type']['contents'] ?? null) === 'document'
                && $request->hasFile('file', $pdf, 'recibo-00000000202601001.pdf');
        });
    }

    public function test_envio_de_guia_vai_com_caption_da_competencia(): void
    {
        $pdf = '%PDF-1.4 conteudo-da-guia';

        $this->dispatch->forceFill([
            'artifact_type' => 'guia',
            'slip_number' => '07202215764027873',
        ])->save();

        Http::fake([
            'wzap.test/instances/inst-1/messages/media' => Http::response(['data' => [
                'message_id' => 'wamid.guia-1',
                'status' => 'sent',
            ]], 202),
            'integra.test/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => json_encode(['pdf' => base64_encode($pdf)]),
                'mensagens' => [['codigo' => '00', 'texto' => 'OK']],
                'responseId' => 'resp-16',
            ]),
        ]);

        $this->handleJob();

        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $this->dispatch->refresh()->status);

        // A legenda fixa nomeia a guia e a competência em MM/AAAA — o
        // destinatário lê "Guia PGDAS 01/2026" no WhatsApp.
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST'
                || $request->url() !== 'http://wzap.test/instances/inst-1/messages/media') {
                return false;
            }

            $partes = collect($request->data())->keyBy('name');

            return ($partes['to']['contents'] ?? null) === '5511987654321'
                && ($partes['caption']['contents'] ?? null) === 'Guia PGDAS 01/2026';
        });
    }

    public function test_envio_sem_contato_vai_para_o_telefone_do_cliente(): void
    {
        $pdf = '%PDF-1.4 conteudo-do-recibo';

        // Disparo do fluxo legado — sem `client_dispatch_contact_id`.
        $this->dispatch->forceFill(['client_dispatch_contact_id' => null])->save();

        Http::fake([
            'wzap.test/instances/inst-1/messages/media' => Http::response(['data' => [
                'message_id' => 'wamid.legado-1',
                'status' => 'sent',
            ]], 202),
            'integra.test/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => json_encode(['recibo' => ['pdf' => base64_encode($pdf)]]),
                'mensagens' => [['codigo' => '00', 'texto' => 'OK']],
                'responseId' => 'resp-15',
            ]),
        ]);

        $this->handleJob();

        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $this->dispatch->refresh()->status);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'http://wzap.test/instances/inst-1/messages/media') {
                return false;
            }

            $partes = collect($request->data())->keyBy('name');

            return ($partes['to']['contents'] ?? null) === '5511987654321';
        });
    }

    public function test_falha_do_wzap_marca_dispatch_como_failed_com_motivo_sanitizado(): void
    {
        Http::fake([
            'integra.test/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => json_encode(['recibo' => ['pdf' => base64_encode('%PDF-1.4')]]),
                'mensagens' => [['codigo' => '00', 'texto' => 'OK']],
                'responseId' => 'resp-15',
            ]),
            'wzap.test/instances/inst-1/messages/media' => Http::response([
                'error' => ['code' => 'not-whatsapp', 'message' => 'numero nao possui WhatsApp'],
            ], 422),
        ]);

        try {
            $this->handleJob();
        } catch (\Throwable) {
            // A exceção pode subir para a fila reentregar — o estado
            // terminal da linha já é o que a tela lê.
        }

        $dispatch = $this->dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $dispatch->status);
        $this->assertStringContainsString('not-whatsapp', (string) $dispatch->failure_reason);
        $this->assertStringNotContainsString('chave-instancia', (string) $dispatch->failure_reason);
        $this->assertNull($dispatch->message_id);
    }

    public function test_falha_de_transporte_marca_failed_sem_vazar_segredo(): void
    {
        Http::fake([
            'integra.test/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => json_encode(['recibo' => ['pdf' => base64_encode('%PDF-1.4')]]),
                'mensagens' => [['codigo' => '00', 'texto' => 'OK']],
                'responseId' => 'resp-15',
            ]),
            'wzap.test/instances/inst-1/messages/media' => Http::response([
                'error' => ['code' => 'internal', 'message' => 'falhou com chave-instancia no texto'],
            ], 500),
        ]);

        try {
            $this->handleJob();
        } catch (\Throwable) {
        }

        $dispatch = $this->dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $dispatch->status);
        $this->assertStringNotContainsString('chave-instancia', (string) $dispatch->failure_reason);
    }

    public function test_contato_removido_apos_o_disparo_falha_sem_cair_no_telefone_da_carteira(): void
    {
        // O membro marcou este contato no Enviar guias; entre a criação
        // da linha e a execução do job o cadastro foi removido. O envio
        // NÃO pode cair no `clients.phone` — um destino que ninguém
        // marcou — então a linha vira `failed` com motivo legível.
        $this->contact->delete();

        $this->handleJob();

        Http::assertSentCount(0);

        $dispatch = $this->dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $dispatch->status);
        $this->assertSame('O Contato de envio foi removido do cadastro do cliente.', $dispatch->failure_reason);
        $this->assertNull($dispatch->message_id);
    }

    public function test_linha_terminal_nao_reenvia(): void
    {
        $this->dispatch->forceFill([
            'status' => WhatsAppDispatch::STATUS_SENT,
            'message_id' => 'wamid.antigo',
        ])->save();

        $this->handleJob();

        Http::assertNothingSent();
        $this->assertSame('wamid.antigo', $this->dispatch->refresh()->message_id);
    }

    public function test_job_com_account_id_errado_nao_envia(): void
    {
        // O job carrega a linha pelo par (id, account_id): um dispatch_id
        // que não pertence à conta declarada termina sem envio.
        $outraConta = Account::factory()->create();

        (new SendWhatsAppDocumentJob($outraConta->getKey(), $this->dispatch->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(WhatsAppDispatch::STATUS_QUEUED, $this->dispatch->refresh()->status);
    }

    public function test_timeout_apos_send_nao_libera_nova_chave_sem_ciencia(): void
    {
        Queue::fake();
        $this->prepareRetryPost();
        $sendCount = 0;
        $this->fakeReceiptDocument();
        Http::fake(['wzap.test/instances/inst-1/messages/media' => function () use (&$sendCount) {
            $sendCount++;
            $this->assertNotNull($this->dispatch->fresh()->send_attempted_at);
            $this->assertFalse($this->dispatch->fresh()->retry_safe);
            throw new ConnectionException('Timeout após aceitação simulada.');
        }]);

        $this->handleJob();
        $this->assertSame(1, $sendCount, (string) $this->dispatch->fresh()->failure_reason);
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $this->dispatch->fresh()->status);
        for ($click = 0; $click < 2; $click++) {
            $this->postJson($this->retryUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
                ->assertUnprocessable()->assertJsonValidationErrors('resend_acknowledgements');
        }
        $this->assertSame(1, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
        $this->assertNotNull($this->dispatch->fresh()->send_attempted_at);
        $this->travel(2)->days();
        $this->postJson($this->retryUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertUnprocessable();
        $this->assertSame(1, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_crash_apos_aceitacao_e_antes_save_nao_libera_nova_chave(): void
    {
        Queue::fake();
        $this->prepareRetryPost();
        $this->fakeReceiptDocument();
        Http::fake(['wzap.test/instances/inst-1/messages/media' => Http::response([
            'data' => ['message_id' => 'accepted-before-crash'],
        ], 202)]);
        $crash = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$crash): void {
            if ($crash && str_starts_with($query, 'update "whatsapp_dispatches"')
                && in_array(WhatsAppDispatch::STATUS_SENT, $bindings, true)) {
                $crash = false;
                throw new \RuntimeException('Crash depois do gateway aceitar.');
            }
        });
        $job = new SendWhatsAppDocumentJob($this->account->getKey(), $this->dispatch->getKey());
        try {
            $job->handle();
            $this->fail('O crash deveria interromper o job.');
        } catch (\RuntimeException $error) {
            $job->failed($error);
        }
        $this->postJson($this->retryUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertUnprocessable()->assertJsonValidationErrors('resend_acknowledgements');
        $this->assertSame(1, WhatsAppDispatch::count());
        $this->assertNotNull($this->dispatch->fresh()->send_attempted_at);
        Queue::assertNothingPushed();
    }

    public function test_job_redelivered_apos_crash_na_fronteira_nao_chama_gateway_de_novo(): void
    {
        $this->fakeReceiptDocument();
        $calls = 0;
        Http::fake(['wzap.test/instances/inst-1/messages/media' => function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('Processo interrompido após send.');
        }]);
        try {
            $this->handleJob();
        } catch (\RuntimeException) {
        }
        try {
            $this->handleJob();
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $calls);
        $this->assertNotNull($this->dispatch->fresh()->send_attempted_at);
    }

    public function test_crash_during_acceptance_cannot_commit_sent_without_gateway_identity(): void
    {
        $this->fakeReceiptDocument();
        Http::fake(['wzap.test/instances/inst-1/messages/media' => Http::response([
            'data' => ['message_id' => 'accepted-atomic'],
        ], 202)]);
        $crashed = false;
        DB::listen(function ($query) use (&$crashed): void {
            if (! $crashed && str_starts_with($query->sql, 'update "whatsapp_dispatches"')
                && in_array(WhatsAppDispatch::STATUS_SENT, $query->bindings, true)) {
                $crashed = true;
                throw new \RuntimeException('Interrupted after acceptance write.');
            }
        });
        try {
            $this->handleJob();
        } catch (\RuntimeException) {
        }

        $this->assertTrue($crashed);
        $dispatch = $this->dispatch->fresh();
        $this->assertFalse($dispatch->status === WhatsAppDispatch::STATUS_SENT && $dispatch->message_id === null);
    }

    public function test_recibo_antecipado_nao_e_rebaixado_pelo_save_ou_falha_do_job(): void
    {
        $this->fakeReceiptDocument();
        Http::fake(['wzap.test/instances/inst-1/messages/media' => function () {
            DB::table('whatsapp_dispatches')->where('id', $this->dispatch->getKey())
                ->update(['status' => WhatsAppDispatch::STATUS_DELIVERED]);

            return Http::response(['data' => ['message_id' => 'accepted-message']], 202);
        }]);
        $this->handleJob();
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $this->dispatch->fresh()->status);
        $this->assertSame('accepted-message', $this->dispatch->fresh()->message_id);
    }

    public function test_recibo_read_antecipado_sobrevive_timeout_do_job(): void
    {
        $this->fakeReceiptDocument();
        Http::fake(['wzap.test/instances/inst-1/messages/media' => function () {
            DB::table('whatsapp_dispatches')->where('id', $this->dispatch->getKey())
                ->update(['status' => WhatsAppDispatch::STATUS_READ]);
            throw new ConnectionException('Timeout após recibo.');
        }]);
        $this->handleJob();
        $this->assertSame(WhatsAppDispatch::STATUS_READ, $this->dispatch->fresh()->status);
        $this->assertNull($this->dispatch->fresh()->failure_reason);
    }

    public function test_failed_legado_sem_evidencia_pre_send_nao_libera_retry(): void
    {
        Queue::fake();
        $this->prepareRetryPost();
        $this->dispatch->forceFill(['status' => WhatsAppDispatch::STATUS_FAILED, 'retry_safe' => false])->save();
        $this->postJson($this->retryUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertUnprocessable()->assertJsonValidationErrors('resend_acknowledgements');
        $this->assertSame(1, WhatsAppDispatch::count());
        Queue::assertNothingPushed();
    }

    public function test_guia_com_envio_incerto_exige_ciencia_e_ciencia_libera_novo_envio(): void
    {
        Queue::fake();
        $this->prepareRetryPost();
        $url = "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/send-guides";
        $payload = ['contact_ids' => [$this->contact->getKey()]];
        $this->dispatch->delete();
        $first = $this->postJson($url, $payload)->assertAccepted();
        $original = WhatsAppDispatch::findOrFail($first->json('data.dispatches.0.id'));
        $original->forceFill(['status' => WhatsAppDispatch::STATUS_FAILED, 'retry_safe' => false, 'send_attempted_at' => now()])->save();
        Queue::fake();
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('resend_acknowledgements');
        $this->postJson($url, $payload)->assertUnprocessable();
        Queue::assertNothingPushed();
        $second = $this->postJson($url, $payload + ['resend_acknowledgements' => [$this->contact->getKey()]])
            ->assertAccepted()->assertJsonPath('data.dispatches.0.status', WhatsAppDispatch::STATUS_QUEUED);
        $this->assertSame(2, WhatsAppDispatch::count());
        $this->assertNotSame($original->idempotency_key, WhatsAppDispatch::findOrFail($second->json('data.dispatches.0.id'))->idempotency_key);
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 1);
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $original->fresh()->status);
    }

    public function test_crash_antes_da_fronteira_preserva_retry_seguro_e_historico(): void
    {
        Queue::fake();
        $this->prepareRetryPost();
        $job = new SendWhatsAppDocumentJob($this->account->getKey(), $this->dispatch->getKey());
        $job->failed(new \RuntimeException('Worker morreu antes de sendMedia.'));
        $this->assertTrue($this->dispatch->fresh()->retry_safe);
        $this->assertNull($this->dispatch->fresh()->send_attempted_at);
        $retry = $this->postJson($this->retryUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertAccepted()->assertJsonPath('data.status', WhatsAppDispatch::STATUS_QUEUED);
        $this->postJson($this->retryUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertAccepted()->assertJsonPath('data.id', $retry->json('data.id'));
        $this->assertSame(2, WhatsAppDispatch::count());
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $this->dispatch->fresh()->status);
        Queue::assertPushed(SendWhatsAppDocumentJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_migration_aditiva_mantem_linhas_legadas_conservadoras(): void
    {
        $migration = require database_path('migrations/2026_10_15_201304_add_send_boundary_to_whatsapp_dispatches_table.php');
        $migration->down();
        $migration->up();
        $legacy = WhatsAppDispatch::findOrFail($this->dispatch->getKey());
        $this->assertFalse($legacy->retry_safe);
        $this->assertNull($legacy->send_attempted_at);
        $this->assertSame($this->dispatch->idempotency_key, $legacy->idempotency_key);
        $this->assertSame(WhatsAppDispatch::STATUS_QUEUED, $legacy->status);
    }

    private function prepareRetryPost(): void
    {
        config()->set('whatsapp.enabled', true);
        $actor = User::factory()->create();
        AccountUser::create(['account_id' => $this->account->getKey(), 'user_id' => $actor->getKey(), 'role' => 'admin']);
        $this->selectBusinessAccount($actor, $this->account->getKey());
        $this->actingAs($actor);
        $this->dispatch->delete();
        $response = $this->postJson($this->retryUrl(), ['competence' => '2026-01', 'artifact' => 'recibo'])
            ->assertAccepted();
        $this->dispatch = WhatsAppDispatch::findOrFail($response->json('data.id'));
        request()->attributes->remove('access.session_lock');
        Queue::fake();
    }

    private function retryUrl(): string
    {
        return "/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$this->client->getKey()}/whatsapp/dispatch";
    }

    private function fakeReceiptDocument(): void
    {
        Http::fake(['integra.test/integra-contador/v1/Consultar' => Http::response([
            'status' => 200, 'dados' => json_encode(['recibo' => ['pdf' => base64_encode('%PDF-1.4')]]),
            'mensagens' => [['codigo' => '00', 'texto' => 'OK']], 'responseId' => 'resp-retry',
        ])]);
    }

    private function handleJob(): void
    {
        (new SendWhatsAppDocumentJob(
            $this->account->getKey(),
            $this->dispatch->getKey(),
        ))->handle();
    }

    /**
     * A credencial da plataforma + termo vigente + e-CNPJ da conta: o mínimo
     * que o `SerproPgdasDocumentReader` exige para chamar o gateway (falso).
     */
    private function contaPronta(): Account
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        if (SerproConnection::current() === null) {
            SerproConnection::factory()->create([
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
                'certificate_encrypted' => Crypt::encryptString($this->pfxDaPlataforma()),
                'certificate_password_encrypted' => Crypt::encryptString('senha'),
                'contratante_numero' => '12345678000195',
                'contratante_tipo' => 2,
                'certificate_valid_until' => now()->addYear(),
            ]);
        }

        SerproAuthorizationTerm::forceCreate([
            'account_id' => $account->getKey(),
            'author_document' => '12345678000195',
            'document_encrypted' => Crypt::encryptString('<termo/>'),
            'document_expires_on' => '2027-01-01',
            'signed_at' => now(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('token-do-termo'),
            'token_expires_at' => CarbonImmutable::parse('2027-01-01'),
        ]);

        AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => '12345678000195',
        ]);

        return $account;
    }

    private function pfxDaPlataforma(): string
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(
            ['CN' => 'SERPRO PLATAFORMA LTDA:12345678000195', 'serialNumber' => '12345678000195'],
            $key,
            array_merge(['digest_alg' => 'sha256'], $config),
        );
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'senha'));

        return $pfx;
    }
}
