<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproManualSearchState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Jobs\RunSerproManualSearchJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Models\SerproManualSearch;
use App\Models\SerproMonitoring;
use App\Services\SerproClientLock;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

/**
 * O trabalhador da busca manual: uma obrigação, um cliente, uma chamada — e
 * o estado da linha contando a história (`queued` → `running` → terminal com
 * motivo). A camada de leitura é a da sincronização; o que não vem dela é a
 * execução, que aqui nem existe.
 */
class SerproManualSearchJobTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-03 12:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_a_busca_reivindica_chama_o_servico_e_completa(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(SerproManualSearchState::Completed, $busca->refresh()->state);
        $this->assertNull($busca->reason);

        // A chamada é auditável e não pertence a execução nenhuma.
        $call = SerproCall::query()->withoutGlobalScope('account')->sole();
        $this->assertSame('CONSDECLARACAO13', $call->id_servico);
        $this->assertNull($call->run_id);
        $this->assertSame($client->getKey(), $call->client_id);

        // A projeção grava na mesma linha que a sincronização alimenta.
        $linha = SerproMonitoring::query()->withoutGlobalScope('account')
            ->where('account_id', $account->getKey())
            ->where('client_id', $client->getKey())
            ->where('obligation', 'declaracoes/pgdas')
            ->firstOrFail();
        $this->assertNotNull($linha->source_at);
        $this->assertNotNull($linha->periods);
    }

    public function test_a_reentrega_de_busca_terminada_nao_repete_a_chamada(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());

        $job = new RunSerproManualSearchJob($busca->getKey(), $account->getKey());
        $job->handle();
        $job->handle();

        // A reentrega que encontra linha terminal reconhece e sai: repetir
        // cobraria o gateway duas vezes pelo mesmo pedido.
        $this->assertSame(1, SerproCall::query()->withoutGlobalScope('account')->count());
        Http::assertSentCount(1);
    }

    public function test_sucesso_com_falha_no_writer_nao_reenvia_na_reentrega(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());
        $falhar = true;
        SerproMonitoring::saving(function (SerproMonitoring $monitoring) use (&$falhar): void {
            if ($falhar && $monitoring->source_at !== null) {
                $falhar = false;

                throw new \RuntimeException('Falha local depois da resposta.');
            }
        });

        try {
            (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();
            $this->fail('O writer deveria interromper a primeira entrega.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Falha local depois da resposta.', $exception->getMessage());
        }

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertSentCount(1);
        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('resposta_incerta', $busca->reason);
    }

    public function test_throttle_na_segunda_unidade_retenta_so_a_segunda(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        config(['integra-contador.obligations.declaracoes/pgdas.service' => 'PGDASD/CONSDECLARACAO13+PGDASD/SEGUNDALEITURA']);
        config(['integra-contador.services.SEGUNDALEITURA' => config('integra-contador.services.CONSDECLARACAO13')]);
        $enviados = [];
        $segundaTentativas = 0;
        Http::fake(function ($request) use (&$enviados, &$segundaTentativas) {
            $servico = $request->data()['pedidoDados']['idServico'];
            $enviados[] = $servico;

            if ($servico === 'SEGUNDALEITURA' && ++$segundaTentativas === 1) {
                return Http::response(['code' => 'RB-00001'], 429);
            }

            return Http::response(['status' => 200, 'dados' => [], 'mensagens' => [], 'responseId' => 'resp-'.$servico]);
        });

        $job = (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(delay: 60);
        $this->assertSame(['PGDASD/CONSDECLARACAO13'], $busca->refresh()->completed_units);
        $this->assertNull($busca->current_unit);
        $this->assertNull($busca->attempted_at);
        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(['CONSDECLARACAO13', 'SEGUNDALEITURA', 'SEGUNDALEITURA'], $enviados);
        $this->assertSame(SerproManualSearchState::Completed, $busca->refresh()->state);
    }

    public function test_a_fronteira_e_duravel_antes_da_rede_e_so_fecha_depois_do_writer(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake(function () use ($account, $client, $busca) {
            $this->assertNull(resolve(SerproClientLock::class)->acquire($account->getKey(), (string) $client->tax_id));
            $busca->refresh();
            $this->assertSame('PGDASD/CONSDECLARACAO13', $busca->current_unit);
            $this->assertNotNull($busca->attempted_at);
            $this->assertSame([], $busca->completed_units ?? []);

            return Http::response(['status' => 200, 'dados' => [], 'mensagens' => [], 'responseId' => 'resposta']);
        });
        SerproMonitoring::saving(function (SerproMonitoring $monitoring) use ($busca): void {
            if ($monitoring->source_at !== null) {
                $this->assertNotNull($busca->refresh()->current_unit);
                $this->assertSame([], $busca->completed_units ?? []);
            }
        });

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(['PGDASD/CONSDECLARACAO13'], $busca->refresh()->completed_units);
        $this->assertNull($busca->current_unit);
        $this->assertNull($busca->attempted_at);
    }

    public function test_rele_estado_terminal_sob_lock_apos_leitura_inicial_obsoleta(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());
        Client::retrieved(function () use ($busca): void {
            SerproManualSearch::withoutGlobalScope('account')->whereKey($busca->getKey())
                ->update(['state' => SerproManualSearchState::Completed->value]);
        });

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(SerproManualSearchState::Completed, $busca->refresh()->state);
    }

    public function test_rele_checkpoint_sob_lock_apos_leitura_inicial_obsoleta(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());
        Client::retrieved(function () use ($busca): void {
            SerproManualSearch::withoutGlobalScope('account')->whereKey($busca->getKey())
                ->update([
                    'state' => SerproManualSearchState::Running->value,
                    'completed_units' => json_encode(['PGDASD/CONSDECLARACAO13']),
                ]);
        });

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(SerproManualSearchState::Completed, $busca->refresh()->state);
    }

    public function test_rele_fronteira_aberta_sob_lock_sem_reenviar(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());
        Client::retrieved(function () use ($busca): void {
            SerproManualSearch::withoutGlobalScope('account')->whereKey($busca->getKey())
                ->update([
                    'state' => SerproManualSearchState::Running->value,
                    'current_unit' => 'PGDASD/CONSDECLARACAO13',
                    'attempted_at' => now(),
                    'completed_units' => '[]',
                ]);
        });

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('resposta_incerta', $busca->reason);
    }

    public function test_chamadas_de_outra_busca_nao_sao_checkpoint_desta_busca(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());
        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();
        $outraBusca = SerproManualSearch::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => $busca->obligation,
        ]);

        (new RunSerproManualSearchJob($outraBusca->getKey(), $account->getKey()))->handle();

        Http::assertSentCount(2);
        $this->assertSame(SerproManualSearchState::Completed, $outraBusca->refresh()->state);
    }

    public function test_upstream_com_response_id_limpa_fronteira_e_retenta(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake(['*/integra-contador/v1/*' => Http::sequence()
            ->push(['status' => 503, 'dados' => null, 'mensagens' => [], 'responseId' => 'recusa'], 503)
            ->push(['status' => 200, 'dados' => [], 'mensagens' => [], 'responseId' => 'sucesso'])]);

        $job = (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(delay: 15);
        $this->assertNull($busca->refresh()->current_unit);
        $this->assertNull($busca->attempted_at);
        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertSentCount(2);
        $this->assertSame(SerproManualSearchState::Completed, $busca->refresh()->state);
    }

    public function test_upstream_sem_response_id_nao_reenvia(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake(['*/integra-contador/v1/*' => Http::response(['status' => 503, 'dados' => null, 'mensagens' => []], 503)]);

        $job = (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotReleased();
        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertSentCount(1);
        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('resposta_incerta', $busca->reason);
    }

    public function test_running_legado_sem_checkpoint_nao_e_prova_de_retentativa_segura(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        $busca->forceFill(['state' => SerproManualSearchState::Running])->save();
        Http::fake($this->fakePgdas());

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('resposta_incerta', $busca->reason);
    }

    public function test_sequencia_sitfis_nao_reabre_fronteira_ao_receber_throttle(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        config(['integra-contador.obligations.declaracoes/pgdas.service' => 'SITFIS/RELATORIOSITFIS92']);
        Http::fake(['*/integra-contador/v1/*' => Http::response(['code' => 'RB-00001'], 429)]);

        $job = (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotReleased();
        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        Http::assertSentCount(1);
        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('resposta_incerta', $busca->reason);
    }

    public function test_sem_procuracao_conhecida_falha_sem_cobrar(): void
    {
        // Nenhuma outorga semeada: é a família que `serpro:refresh-powers`
        // manteria observada, e sem ela a chamada nem sai.
        [$account, $client, $busca] = $this->cenarioChamavel(semOutorga: true);
        Http::fake($this->fakePgdas());

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('sem_procuracao', $busca->reason);
        $this->assertDatabaseHas('serpro_monitorings', [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'cause' => 'sem_procuracao',
        ]);
        $this->assertSame(0, SerproCall::query()->withoutGlobalScope('account')->count());
        Http::assertNothingSent();
    }

    public function test_a_recusa_022_marca_a_familia_aceita_como_rejected(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();

        // A outorga que a elegibilidade viu é recusada pelo serviço: é o
        // cancelamento no e-CAC que o oráculo ainda não sabe.
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 403,
                'dados' => null,
                'mensagens' => [[
                    'codigo' => 'AcessoNegado-ICGERENCIADOR-022',
                    'texto' => 'Não possui procuração outorgada no e-CAC para o contribuinte.',
                ]],
            ], 403),
        ]);

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('sem_procuracao', $busca->reason);

        // A família que a elegibilidade tinha aceitado passa a `rejected` —
        // o estado que a próxima busca recusa sem rede nem cobrança.
        $autorizacao = SerproClientAuthorization::query()
            ->where('account_id', $account->getKey())
            ->where('client_id', $client->getKey())
            ->where('family', '00146')
            ->sole();
        $this->assertSame(SerproPowerOfAttorneyState::Rejected, $autorizacao->state);
        $this->assertNotNull($autorizacao->verified_at);

        $this->assertDatabaseHas('serpro_monitorings', [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'cause' => 'sem_procuracao',
        ]);
        $this->assertSame(1, SerproCall::query()->withoutGlobalScope('account')->count());
        Http::assertSentCount(1);
    }

    public function test_cliente_sem_documento_falha_sem_chamar(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel(semDocumento: true);
        Http::fake($this->fakePgdas());

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('sem_documento', $busca->reason);
        Http::assertNothingSent();
    }

    public function test_conta_desligada_no_meio_da_fila_falha_com_motivo(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());

        $account->forceFill(['settings' => ['serpro_enabled' => false]])->save();

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('conta_desligada', $busca->reason);
        Http::assertNothingSent();
    }

    public function test_o_throttle_reagenda_com_a_busca_running(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake(['*/integra-contador/v1/*' => Http::response(['code' => 'RB-00001', 'message' => 'muitas requisições'], 429)]);

        $job = (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->withFakeQueueInteractions();
        $job->handle();

        // O throttle é "depois", não "não": a busca volta à fila com o estado
        // de trabalho preservado, e a reentrega segue de onde parou.
        $job->assertReleased(delay: 60);
        $this->assertSame(SerproManualSearchState::Running, $busca->refresh()->state);
    }

    public function test_o_lock_tomado_por_outro_devolve_o_job_a_fila(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel();
        Http::fake($this->fakePgdas());

        $lock = resolve(SerproClientLock::class);
        $ocupado = $lock->acquire($account->getKey(), (string) $client->tax_id);
        $this->assertTrue($ocupado !== null);

        $job = (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(delay: 15);
        $this->assertSame(0, SerproCall::query()->withoutGlobalScope('account')->count());
        Http::assertNothingSent();

        $ocupado->release();
    }

    public function test_obrigacao_sem_leitura_servida_falha_com_motivo_proprio(): void
    {
        [$account, $client, $busca] = $this->cenarioChamavel(obligation: 'parcelamentos/pgfn');
        Http::fake($this->fakePgdas());

        (new RunSerproManualSearchJob($busca->getKey(), $account->getKey()))->handle();

        $this->assertSame(SerproManualSearchState::Failed, $busca->refresh()->state);
        $this->assertSame('obrigacao_sem_leitura', $busca->reason);
        Http::assertNothingSent();
    }

    /**
     * Conta habilitada, certificado, termo vigente, PJ com documento e a
     * outorga da família 00146 — que é a que o `declaracoes/pgdas` exige e a
     * que `serpro:refresh-powers` manteria observada.
     *
     * @return array{0: Account, 1: Client, 2: SerproManualSearch}
     */
    private function cenarioChamavel(bool $semDocumento = false, bool $semOutorga = false, string $obligation = 'declaracoes/pgdas'): array
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
            'token_expires_at' => CarbonImmutable::parse('2026-10-04'),
        ]);

        AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => '12345678000195',
        ]);

        // O par de tokens vai em cache: sem a semente a autenticação sairia
        // como uma segunda chamada HTTP.
        Cache::put($this->serproTokenCacheKey(), new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => $semDocumento ? null : '33683111000875',
        ]);

        if (! $semDocumento && ! $semOutorga && $obligation === 'declaracoes/pgdas') {
            SerproClientAuthorization::factory()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
                'family' => '00146',
                'code' => '00146',
            ]);
        }

        $busca = SerproManualSearch::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => $obligation,
        ]);

        return [$account, $client, $busca];
    }

    /**
     * O PGDAS responde com um período transmitido e o DAS pago.
     *
     * @return array<string, mixed>
     */
    private function fakePgdas(): array
    {
        return [
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => [
                    'periodos' => [[
                        'periodoApuracao' => '202609',
                        'operacoes' => [[
                            'tipoOperacao' => 'Original',
                            'indiceDeclaracao' => [
                                'numeroDeclaracao' => 'PGD-1',
                                'dataHoraTransmissao' => '20261001120000',
                            ],
                            'indiceDas' => [
                                'numeroDas' => 'DAS-1',
                                'dataHoraEmissaoDas' => '20261002120000',
                                'dasPago' => true,
                            ],
                        ]],
                    ]],
                ],
                'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Requisição efetuada com sucesso']],
                'responseId' => 'resp-pgdas',
            ]),
            '*' => Http::response(['status' => 200, 'dados' => '{}', 'mensagens' => [], 'responseId' => 'resp-2']),
        ];
    }

    /**
     * O mesmo PFX que `SerproClientTest` gera em runtime: `assertIdentity()`
     * recusa certificado de mentira antes de qualquer rede.
     */
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
