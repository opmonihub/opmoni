<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Http\Middleware\AccessSecurity;
use App\Http\Middleware\SerializedAccessSession;
use App\Http\Middleware\TrustedIngress;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproConnection;
use App\Models\SerproMonitoring;
use App\Models\SupportAccessLog;
use App\Models\User;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BusinessTestCase;

/**
 * A leitura de mensagem da caixa postal é ciência da intimação (D19): toda
 * recusa tem de acontecer antes de `MSGDETALHAMENTO62` sair, e por isso cada
 * caminho de recusa afirma que nada foi enviado ao provedor.
 */
class SerproMailboxReadTest extends BusinessTestCase
{
    use RefreshDatabase;

    private const OBRIGACAO = 'caixas-postais/e-cac';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;

        // Explicit isolated contract fixture, NOT proof of same-database PostgreSQL durability.
        $sidecar = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $sidecar->statement('CREATE TABLE external_effect_journal (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, actor_id INTEGER, operation_id TEXT, resource_hash TEXT, effect_key TEXT UNIQUE, step TEXT, state TEXT, receipt TEXT, owner_token TEXT, version INTEGER, payload_encrypted TEXT, armed_at TEXT, confirmed_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->app->instance(ExternalEffectJournalConnection::class, new ExternalEffectJournalConnection(fn (): Connection => $sidecar));
        Route::prefix('api/v1')->middleware([TrustedIngress::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, SerializedAccessSession::class, AccessSecurity::class, 'auth'])->group(function (): void {
            if (file_exists(base_path('routes/external-mailbox.php'))) {
                require base_path('routes/external-mailbox.php');
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_sem_confirmacao_da_ciencia_recusa_e_nao_chama_o_provedor(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'operador'), 'web')
            ->postJson($this->rota($client, 82838), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ciencia');

        $this->actingAs($this->memberOf($account, 'operador'), 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => false])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_membro_user_nao_registra_ciencia(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'user'), 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_mensagem_fora_da_caixa_sincronizada_do_cliente_e_404(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'admin'), 'web')
            ->postJson($this->rota($client, 99999), ['ciencia' => true])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_obrigacao_sem_caixa_postal_e_404(): void
    {
        [$account, $client] = $this->cenario();
        $this->linha($client, 'declaracoes/pgdas', [['id' => 82838]]);
        Http::fake();

        $this->actingAs($this->memberOf($account, 'admin'), 'web')
            ->postJson("/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$client->getKey()}/messages/82838", ['ciencia' => true])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_cliente_de_outra_conta_e_404(): void
    {
        [, $client] = $this->cenario();
        $vizinha = Account::factory()->create();
        Http::fake();

        $this->actingAs($this->memberOf($vizinha, 'admin'), 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_com_confirmacao_le_a_mensagem_monta_o_corpo_e_marca_o_stub(): void
    {
        [$account, $client] = $this->cenario();

        Http::fake([
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => json_encode([
                    'codigo' => '00',
                    'conteudo' => [[
                        'isn' => '0000082838',
                        'assuntoModelo' => '[IRPF] Declaração do exercício ++VARIAVEL++ processada',
                        'valorParametroAssunto' => '2026',
                        'numeroControle' => '2026/000000000000001',
                        'dataLeitura' => '20260928',
                        'horaLeitura' => '120000',
                        'dataCiencia' => '20260928',
                        'dataExpiracao' => '20261028',
                        'corpoModelo' => '<p>A declaração ++1++ está em ++2++</p><p>Fim &amp; ok</p>',
                        'variaveis' => ['2026', '<b>e-CAC</b>'],
                    ]],
                ]),
                'mensagens' => [['codigo' => '00', 'texto' => 'Recuperação OK.']],
                'responseId' => 'resp-62',
            ]),
        ]);

        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertOk()
            ->assertJsonPath('data.id', 82838)
            ->assertJsonPath('data.codigo', '2026/000000000000001')
            ->assertJsonPath('data.assunto', '[IRPF] Declaração do exercício 2026 processada')
            // A variável entra como texto: a tag dela não vira marcação removida.
            ->assertJsonPath('data.corpo', "A declaração 2026 está em <b>e-CAC</b>\nFim & ok")
            ->assertJsonPath('data.ciencia_em', '2026-09-28')
            ->assertJsonPath('data.prazo_limite', '2026-10-28');

        Http::assertSent(function ($request) use ($client): bool {
            $body = $request->data();

            return ($body['pedidoDados']['idServico'] ?? null) === 'MSGDETALHAMENTO62'
                && ($body['pedidoDados']['idSistema'] ?? null) === 'CAIXAPOSTAL'
                && json_decode($body['pedidoDados']['dados'] ?? '', true) === ['isn' => '0000082838']
                && ($body['contribuinte']['numero'] ?? null) === $client->tax_id;
        });

        $stub = collect(SerproMonitoring::query()->withoutGlobalScope('account')->sole()->messages)->firstWhere('id', 82838);
        $this->assertFalse($stub['unread']);
        $this->assertSame('2026-09-28', $stub['ciencia_em']);

        $call = SerproCall::query()->withoutGlobalScope('account')->sole();
        $this->assertNull($call->run_id);
        $this->assertSame('MSGDETALHAMENTO62', $call->id_servico);
        $this->assertSame($client->getKey(), $call->client_id);
        // A ciência é ato de alguém: a chamada guarda quem a registrou.
        $this->assertSame($operador->getKey(), $call->user_id);
    }

    public function test_diagnostico_lease_perdido_na_autenticacao_impede_envio_de_msgdetalhamento62(): void
    {
        [$account, $client] = $this->cenario();
        Cache::forget($this->serproTokenCacheKey());
        $autenticacaoInvalidouLease = false;
        $this->fakeLeituraComSucesso();

        Http::fake([
            (string) config('integra-contador.auth_url') => function (Request $request) use (&$autenticacaoInvalidouLease): PromiseInterface {
                $this->assertSame('client_credentials', $request['grant_type']);
                $lock = request()->attributes->get('access.session_lock');
                $this->assertInstanceOf(Lock::class, $lock);
                $this->assertTrue($lock->isOwnedByCurrentProcess());
                $lock->forceRelease();
                $this->assertFalse($lock->isOwnedByCurrentProcess());
                $autenticacaoInvalidouLease = true;

                return Http::response([
                    'access_token' => 'access-apos-perda-do-lease',
                    'jwt_token' => 'jwt-apos-perda-do-lease',
                    'expires_in' => 2008,
                ]);
            },
        ]);

        $this->actingAs($this->memberOf($account, 'operador'), 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertStatus(503)
            ->assertJsonPath('code', 'service_unavailable');

        $this->assertTrue($autenticacaoInvalidouLease);
        Http::assertNotSent(fn (Request $request): bool => ($request['pedidoDados']['idServico'] ?? null) === 'MSGDETALHAMENTO62');
    }

    /**
     * Caracterização diagnóstica, não garantia de segurança: rollback local
     * não desfaz a ciência que o provedor já aplicou fora da transação.
     */
    public function test_diagnostico_lease_perdido_apos_efeito_externo_reverte_projecao_e_auditoria_mas_nao_ciencia(): void
    {
        [$account, $client] = $this->cenario();
        $registro = SerproMonitoring::query()->withoutGlobalScope('account')->sole();
        $mensagensAntes = $registro->messages;
        $cienciaAplicadaForaDoBanco = false;
        $this->assertSame(0, SerproCall::query()->withoutGlobalScope('account')->count());

        $this->fakeLeituraComSucesso(function (Request $request) use (&$cienciaAplicadaForaDoBanco): void {
            $this->assertSame('MSGDETALHAMENTO62', $request['pedidoDados']['idServico']);
            $lock = request()->attributes->get('access.session_lock');
            $this->assertInstanceOf(Lock::class, $lock);
            $this->assertTrue($lock->isOwnedByCurrentProcess());
            $cienciaAplicadaForaDoBanco = true;
            $lock->forceRelease();
            $this->assertFalse($lock->isOwnedByCurrentProcess());
        });

        $this->actingAs($this->memberOf($account, 'operador'), 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertStatus(503)
            ->assertJsonPath('code', 'service_unavailable');

        Http::assertSent(fn (Request $request): bool => ($request['pedidoDados']['idServico'] ?? null) === 'MSGDETALHAMENTO62');
        Http::assertSentCount(1);
        $this->assertTrue($cienciaAplicadaForaDoBanco, 'O 503 e o rollback local não desfazem a ciência aplicada no provedor.');
        $this->assertSame($mensagensAntes, $registro->refresh()->messages);
        $this->assertSame(0, SerproCall::query()->withoutGlobalScope('account')->count());
        $effect = resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->sole();
        $this->assertSame('confirmed', $effect->state);
        $this->assertSame('applied', $effect->receipt);
        Http::fake();
        $this->actingAs($this->memberOf($account, 'admin'))->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_confirmed');
        Http::assertNothingSent();
    }

    public function test_suporte_registra_ciencia_e_o_ato_vai_para_a_auditoria_requires_real_membership(): void
    {
        $targetAccount = Account::factory()->create();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $targetAccount->id);
        $this->actingAs($superAdmin)->postJson('/api/v1/serpro/monitoring/obligations/caixas-postais/e-cac/clients/1/messages/1', [])
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_membro_que_registra_ciencia_nao_gera_log_de_suporte(): void
    {
        [$account, $client] = $this->cenario();
        $this->fakeLeituraComSucesso();

        $this->actingAs($this->memberOf($account, 'admin'), 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertOk();

        $this->assertSame(0, SupportAccessLog::count());
    }

    public function test_falha_do_provedor_responde_o_rotulo_e_nao_o_texto_dele(): void
    {
        [$account, $client] = $this->cenario();

        Http::fake([
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 400,
                'dados' => '',
                'mensagens' => [['codigo' => 'Erro-99', 'texto' => 'texto interno do provedor']],
                'responseId' => 'resp-erro',
            ], 400),
        ]);

        $response = $this->actingAs($this->memberOf($account, 'admin'), 'web')
            ->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()
            ->assertJsonPath('code', 'operation_uncertain');

        $this->assertStringNotContainsString('texto interno do provedor', $response->getContent());

        $stub = collect(SerproMonitoring::query()->withoutGlobalScope('account')->sole()->messages)->firstWhere('id', 82838);
        $this->assertTrue($stub['unread']);
    }

    public function test_a_listagem_nao_le_mensagem_nenhuma(): void
    {
        [$account] = $this->cenario();
        Http::fake();

        $this->actingAs($this->memberOf($account, 'admin'), 'web')
            ->getJson('/api/v1/serpro/monitoring/obligations/'.self::OBRIGACAO)
            ->assertOk()
            ->assertJsonPath('data_rows.0.message.id', 82838);

        Http::assertNothingSent();
    }

    public function test_receipt_is_confirmed_before_ordinary_recorder_save_can_fail(): void
    {
        [$account, $client] = $this->cenario();
        $this->fakeLeituraComSucesso();
        SerproCall::creating(function (): void {
            throw new \RuntimeException('ordinary projection unavailable');
        });
        $actor = $this->memberOf($account, 'admin');
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_confirmed');
        $effect = resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->sole();
        $this->assertSame('confirmed', $effect->state);
        $this->assertSame('applied', $effect->receipt);
        $payload = resolve(ExternalEffectJournal::class)->forResource($account->id, $effect->resource_hash)[0]['payload'];
        $this->assertArrayNotHasKey('assunto', $payload);
        $this->assertArrayNotHasKey('corpo', $payload);
        Http::fake();
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_pending_failure_before_service_send_allows_fresh_attempt(): void
    {
        [$account, $client] = $this->cenario();
        Cache::forget($this->serproTokenCacheKey());
        Http::fake([(string) config('integra-contador.auth_url') => Http::failedConnection()]);
        $actor = $this->memberOf($account, 'operador');
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertStatus(502)->assertJsonPath('code', 'operation_before_send');
        $this->assertSame('pending', resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->sole()->state);
        Cache::put($this->serproTokenCacheKey(), new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);
        $this->fakeLeituraComSucesso();
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])->assertOk();
    }

    public function test_confirmed_effect_blocks_new_read_before_credentials_and_explicit_recovery_never_calls_provider(): void
    {
        [$account, $client] = $this->cenario();
        $this->fakeLeituraComSucesso();
        $actor = $this->memberOf($account, 'operador');
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])->assertOk()->assertJsonPath('data.body_available', true);
        SerproAuthorizationTerm::query()->withoutGlobalScope('account')->delete();
        AccountCertificate::query()->withoutGlobalScope('account')->delete();
        $registro = SerproMonitoring::query()->withoutGlobalScope('account')->sole();
        $registro->update(['messages' => [['id' => 82838, 'assunto' => 'Intimação', 'unread' => true]]]);
        Http::fake();
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_confirmed')->assertJsonPath('effect_status', 'confirmed');
        $this->actingAs($actor)->postJson($this->rota($client, 82838).'/reconcile')
            ->assertOk()->assertJsonPath('data.effect_status', 'confirmed')->assertJsonPath('data.body_available', false)
            ->assertJsonPath('data.ciencia_em', '2026-09-28')->assertJsonMissingPath('data.corpo')->assertJsonMissingPath('data.assunto');
        $this->assertFalse($registro->refresh()->messages[0]['unread']);
        Http::assertNothingSent();
    }

    public function test_uncertain_effect_never_resends_even_after_time_passes_and_recovery_does_not_claim_success(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake(['*/integra-contador/v1/Consultar' => Http::failedConnection()]);
        $actor = $this->memberOf($account, 'admin');
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        $this->travel(40)->days();
        Http::fake();
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        $this->actingAs($actor)->postJson($this->rota($client, 82838).'/reconcile')
            ->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        Http::assertNothingSent();
        $this->assertTrue(SerproMonitoring::query()->withoutGlobalScope('account')->sole()->messages[0]['unread']);
    }

    public function test_recovery_requires_role_and_current_account_resource(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake();
        $this->actingAs($this->memberOf($account, 'user'))->postJson($this->rota($client, 82838).'/reconcile')->assertForbidden();
        $other = Account::factory()->create();
        $this->actingAs($this->memberOf($other, 'admin'))->postJson($this->rota($client, 82838).'/reconcile')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unauthorized_provider_response_is_not_automatically_retried(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake(['*/integra-contador/v1/Consultar' => Http::response(['code' => '401'], 401)]);
        $this->actingAs($this->memberOf($account, 'admin'))->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        Http::assertSentCount(1);
    }

    public function test_malformed_success_stays_uncertain_without_synthetic_empty_message(): void
    {
        [$account, $client] = $this->cenario();
        Http::fake(['*/integra-contador/v1/Consultar' => Http::response(['status' => 200, 'dados' => '{}'])]);
        $actor = $this->memberOf($account, 'operador');
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        Http::fake();
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_observed_mailbox_response_without_returned_isn_preserves_real_body_and_nullable_receipt(): void
    {
        [$account, $client] = $this->cenario();
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/serpro/caixapostal-detalhar-mensagem.json')), true, flags: JSON_THROW_ON_ERROR);
        $content = json_decode($fixture['dados'], true, flags: JSON_THROW_ON_ERROR)['conteudo'][0];
        $this->assertArrayNotHasKey('isn', $content);
        $this->assertSame('', $content['dataCiencia']);
        Http::fake(['*/integra-contador/v1/Consultar' => Http::response($fixture)]);
        $actor = $this->memberOf($account, 'operador');

        $response = $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertOk()->assertJsonPath('data.body_available', true)
            ->assertJsonPath('data.ciencia_em', null)->assertJsonPath('data.prazo_limite', '2023-06-21');
        $this->assertStringContainsString('Prezado(a) Contribuinte', $response->json('data.corpo'));
        $this->assertStringContainsString('202200000263', $response->json('data.corpo'));
        $row = resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->sole();
        $effect = resolve(ExternalEffectJournal::class)->forResource($account->id, $row->resource_hash)[0];
        $this->assertSame('confirmed', $effect['state']);
        $this->assertSame($actor->id, $effect['actor_id']);
        $this->assertNull($effect['payload']['ciencia_em']);
        $this->assertStringStartsWith('2022-06-23T17:00:51', $effect['payload']['lida_em']);
        $this->assertSame([], array_diff(array_keys($effect['payload']), ['lida_em', 'ciencia_em', 'prazo_limite', 'http_status', 'request_tag', 'response_id', 'provider_code', 'codigo']));
        $this->assertStringNotContainsString('Prezado', json_encode($effect['payload']));
        Http::assertSentCount(1);
    }

    #[DataProvider('mismatchedMailboxRequestEchoes')]
    public function test_mismatched_request_echo_never_confirms_mailbox_receipt(mixed $echo): void
    {
        [$account, $client] = $this->cenario();
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/serpro/caixapostal-detalhar-mensagem.json')), true, flags: JSON_THROW_ON_ERROR);
        $fixture['pedidoDados'] = $echo;
        Http::fake(['*/integra-contador/v1/Consultar' => Http::response($fixture)]);
        $actor = $this->memberOf($account, 'operador');

        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        $this->assertSame('uncertain', resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->sole()->state);
        Http::fake();
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])->assertConflict();
        Http::assertNothingSent();
    }

    public static function mismatchedMailboxRequestEchoes(): array
    {
        $valid = ['idSistema' => 'CAIXAPOSTAL', 'idServico' => 'MSGDETALHAMENTO62', 'dados' => '{"isn":"0000082838"}'];

        return [
            'different message' => [[...$valid, 'dados' => '{"isn":"0000082839"}']],
            'different service' => [[...$valid, 'idServico' => 'MSGCONTRIBUINTE61']],
            'different system' => [[...$valid, 'idSistema' => 'OUTRO']],
            'malformed data' => [[...$valid, 'dados' => 'not-json']],
            'null echo' => [null],
        ];
    }

    #[DataProvider('unconfirmableMailboxResponses')]
    public function test_invalid_receipt_never_confirms_or_resends(array $dados): void
    {
        [$account, $client] = $this->cenario();
        Http::fake(['*/integra-contador/v1/Consultar' => Http::response(['status' => 200, 'dados' => json_encode($dados)])]);
        $actor = $this->memberOf($account, 'operador');
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        $this->assertSame('uncertain', resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->sole()->state);
        Http::fake();
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])->assertConflict();
        $this->actingAs($actor)->postJson($this->rota($client, 82838).'/reconcile')->assertConflict();
        Http::assertNothingSent();
    }

    public static function unconfirmableMailboxResponses(): array
    {
        $item = ['assuntoModelo' => 'Intimação', 'corpoModelo' => 'Texto', 'dataCiencia' => '20260928'];

        return [
            'empty item' => [['codigo' => '00', 'conteudo' => [[]]]],
            'identity only' => [['codigo' => '00', 'conteudo' => [['isn' => '0000082838']]]],
            'mismatched returned isn' => [['codigo' => '00', 'conteudo' => [[...$item, 'isn' => '0000082839']]]],
            'multiple messages' => [['codigo' => '00', 'conteudo' => [$item, $item]]],
            'business error' => [['codigo' => '99', 'conteudo' => [$item]]],
            'invalid calendar date' => [['codigo' => '00', 'conteudo' => [[...$item, 'dataCiencia' => '20260230']]]],
            'invalid clock' => [['codigo' => '00', 'conteudo' => [[...$item, 'dataLeitura' => '20260928', 'horaLeitura' => '250000']]]],
            'invalid unused fallback date' => [['codigo' => '00', 'conteudo' => [[...$item, 'dataValidade' => '20261028', 'dataExpiracao' => '20260230']]]],
        ];
    }

    #[DataProvider('canonicalMailboxEffects')]
    public function test_same_provider_message_is_blocked_across_authorized_mailbox_views(string $source, string $target, bool $uncertain): void
    {
        [$account, $client] = $this->cenario();
        foreach (['caixas-postais/fgts-digital', 'caixas-postais/det'] as $slug) {
            $this->linha($client, $slug, [['id' => 82838, 'assunto' => 'Intimação', 'unread' => true]]);
        }
        if ($uncertain) {
            Http::fake(['*/integra-contador/v1/Consultar' => Http::failedConnection()]);
        } else {
            $this->fakeLeituraComSucesso();
        }
        $actor = $this->memberOf($account, 'admin');
        $sourceUri = str_replace(self::OBRIGACAO, $source, $this->rota($client, 82838));
        $targetUri = str_replace(self::OBRIGACAO, $target, $this->rota($client, 82838));
        $this->actingAs($actor)->postJson($sourceUri, ['ciencia' => true])->assertStatus($uncertain ? 409 : 200);
        Http::assertSentCount(1);
        Http::fake();
        $this->actingAs($actor)->postJson($targetUri, ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', $uncertain ? 'operation_uncertain' : 'operation_confirmed');
        $recovery = $this->actingAs($actor)->postJson($targetUri.'/reconcile');
        if ($uncertain) {
            $recovery->assertConflict()->assertJsonPath('code', 'operation_uncertain');
        } else {
            $recovery->assertOk()->assertJsonPath('data.body_available', false)->assertJsonPath('data.ciencia_em', '2026-09-28');
        }
        Http::assertNothingSent();
        $this->assertSame(1, resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->count());
        $this->actingAs($actor)->postJson(str_replace('/82838', '/99999', $targetUri).'/reconcile')->assertNotFound();
        DB::table('clients')->where('id', $client->id)->update(['tax_id' => '12345678000195']);
        $this->actingAs($actor)->postJson($targetUri.'/reconcile')->assertConflict()->assertJsonPath('code', 'operation_not_confirmed');
    }

    public static function canonicalMailboxEffects(): array
    {
        return [
            'confirmed ecac to fgts' => [self::OBRIGACAO, 'caixas-postais/fgts-digital', false],
            'confirmed det to ecac' => ['caixas-postais/det', self::OBRIGACAO, false],
            'uncertain ecac to det' => [self::OBRIGACAO, 'caixas-postais/det', true],
            'uncertain fgts to ecac' => ['caixas-postais/fgts-digital', self::OBRIGACAO, true],
        ];
    }

    #[DataProvider('missingMailboxCredentials')]
    public function test_missing_credential_is_explicitly_before_send_and_allows_fresh_consent(string $missing): void
    {
        [$account, $client] = $this->cenario();
        $credential = $missing === 'term'
            ? SerproAuthorizationTerm::query()->withoutGlobalScope('account')->sole()
            : AccountCertificate::query()->withoutGlobalScope('account')->sole();
        $attributes = $credential->getAttributes();
        $credential->delete();
        $this->fakeLeituraComSucesso();
        $actor = $this->memberOf($account, 'operador');
        $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true])
            ->assertConflict()->assertJsonPath('code', 'operation_before_send')->assertJsonPath('effect_status', 'pending');
        Http::assertNothingSent();
        $this->assertSame(0, resolve(ExternalEffectJournalConnection::class)->connection()->table('external_effect_journal')->count());
        $credential->getConnection()->table($credential->getTable())->insert($attributes);
        $this->fakeLeituraComSucesso();
        $response = $this->actingAs($actor)->postJson($this->rota($client, 82838), ['ciencia' => true]);
        $this->assertSame(200, $response->status(), $response->getContent());
        Http::assertSentCount(1);
    }

    public static function missingMailboxCredentials(): array
    {
        return [['term'], ['certificate']];
    }

    /**
     * @return array{0: Account, 1: Client}
     */
    private function cenario(): array
    {
        $account = $this->contaPronta();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        $this->linha($client, self::OBRIGACAO, [[
            'id' => 82838,
            'assunto' => 'Intimação',
            'received_at' => '2026-09-20',
            'lida_em' => null,
            'ciencia_em' => null,
            'prazo_limite' => null,
            'unread' => true,
        ]]);

        Cache::put($this->serproTokenCacheKey(), new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        return [$account, $client];
    }

    /**
     * @param  (Closure(Request): void)|null  $antesDaResposta
     */
    private function fakeLeituraComSucesso(?Closure $antesDaResposta = null): void
    {
        Http::fake([
            '*/integra-contador/v1/Consultar' => function (Request $request) use ($antesDaResposta): PromiseInterface {
                if ($antesDaResposta !== null) {
                    $antesDaResposta($request);
                }

                return Http::response([
                    'status' => 200,
                    'dados' => json_encode([
                        'codigo' => '00',
                        'conteudo' => [[
                            'isn' => '0000082838',
                            'assuntoModelo' => 'Intimação',
                            'dataCiencia' => '20260928',
                            'dataExpiracao' => '20261028',
                            'corpoModelo' => '<p>Texto</p>',
                        ]],
                    ]),
                    'mensagens' => [['codigo' => '00', 'texto' => 'Recuperação OK.']],
                    'responseId' => 'resp-62',
                ]);
            },
        ]);
    }

    private function rota(Client $client, int $isn): string
    {
        return '/api/v1/serpro/monitoring/obligations/'.self::OBRIGACAO."/clients/{$client->getKey()}/messages/{$isn}";
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function linha(Client $client, string $obrigacao, array $messages): SerproMonitoring
    {
        return SerproMonitoring::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
            'obligation' => $obrigacao,
            'messages' => $messages,
            'source_at' => '2026-09-26 10:00:00',
        ]);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }

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
            'token_expires_at' => CarbonImmutable::parse('2026-09-29'),
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

        $certificate = $this->signScenarioCertificate($csr, $key);
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'senha'));

        return $pfx;
    }
}
