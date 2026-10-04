<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproFailure;
use App\Jobs\SyncSerproClientJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproConnection;
use App\Models\SerproMonitoring;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Services\SerproCallRecorder;
use App\Services\SerproException;
use App\Services\SerproMonitoringMapper;
use App\Services\SerproResult;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A chamada registrada e a linha projetada: cada serviço habilitado sai com
 * os parâmetros que o provedor documentou, grava a auditoria em
 * `serpro_calls` e devolve ao painel só as colunas que o contrato declara.
 */
class SerproSyncProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
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

    public function test_a_caixa_postal_sai_com_os_parametros_documentados_e_projeta_as_mensagens(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel(['caixas-postais/e-cac']);
        Http::fake($this->fakesDeSucesso());

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->handle();
        $job->handle();

        $call = SerproCall::query()->where('id_servico', 'MSGCONTRIBUINTE61')->sole();
        $this->assertSame('Consultar', $call->path);
        $this->assertSame('1.0', $call->version);
        $this->assertTrue($call->billable);
        $this->assertSame('resp-MSGCONTRIBUINTE61', $call->response_id);
        $this->assertSame(32, strlen($call->request_tag));
        $this->assertSame(SerproFailure::Success, $call->status);

        // O pedido é a primeira página completa: sem filtro de leitura, sem
        // ponteiro de página e sem marcação de favorito — a lista que sobra
        // é a que o provedor tem, e não um recorte.
        Http::assertSent(fn ($request): bool => ($request->data()['pedidoDados']['idServico'] ?? null) === 'MSGCONTRIBUINTE61'
            && json_decode($request->data()['pedidoDados']['dados'], true) === [
                'categoria' => '0',
                'statusLeitura' => '0',
                'indicadorPagina' => '0',
            ]);

        $monitoring = SerproMonitoring::query()
            ->where('obligation', 'caixas-postais/e-cac')->sole();

        $this->assertSame([
            'nao_lidas' => 2,
            'ultima' => '2026-09-20',
            'mais_paginas' => false,
        ], $monitoring->fields);
        $this->assertNotNull($monitoring->source_at);
        $this->assertNull($monitoring->cause);

        $this->assertSame([
            [
                'id' => 1626772,
                'assunto' => 'Notificação nº 00160556',
                'received_at' => '2026-09-20',
                'lida_em' => null,
                'ciencia_em' => '2026-09-21',
                'prazo_limite' => '2026-10-05',
                'unread' => true,
            ],
            [
                'id' => 1626771,
                'assunto' => 'Aviso de edital eletrônico',
                'received_at' => '2026-09-10',
                'lida_em' => '2026-09-11 08:30:05',
                'ciencia_em' => '2026-09-11',
                'prazo_limite' => null,
                'unread' => false,
            ],
            [
                'id' => 1626770,
                'assunto' => 'FGTS Digital — guia disponível',
                'received_at' => '2026-09-15',
                'lida_em' => null,
                'ciencia_em' => null,
                'prazo_limite' => null,
                'unread' => true,
            ],
            [
                'id' => 1626769,
                'assunto' => 'Comunicação DET — prazo',
                'received_at' => '2026-09-05',
                'lida_em' => '2026-09-06 10:00:00',
                'ciencia_em' => '2026-09-06',
                'prazo_limite' => '2026-09-30',
                'unread' => false,
            ],
        ], $monitoring->messages);

        $fgts = SerproMonitoring::query()
            ->where('obligation', 'caixas-postais/fgts-digital')->sole();
        $this->assertSame(1, $fgts->fields['nao_lidas']);
        $this->assertSame('2026-09-15', $fgts->fields['ultima']);
        $this->assertSame([
            [
                'id' => 1626770,
                'assunto' => 'FGTS Digital — guia disponível',
                'received_at' => '2026-09-15',
                'lida_em' => null,
                'ciencia_em' => null,
                'prazo_limite' => null,
                'unread' => true,
            ],
        ], $fgts->messages);

        $det = SerproMonitoring::query()
            ->where('obligation', 'caixas-postais/det')->sole();
        $this->assertSame(0, $det->fields['nao_lidas']);
        $this->assertSame('2026-09-05', $det->fields['ultima']);
        $this->assertSame('Comunicação DET — prazo', $det->messages[0]['assunto']);
    }

    public function test_a_segunda_execucao_renova_o_carimbo_no_lugar_e_preserva_o_estado(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel(['caixas-postais/e-cac']);
        Http::fake($this->fakesDeSucesso());

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->handle();
        $job->handle();

        $monitoring = SerproMonitoring::query()
            ->where('obligation', 'caixas-postais/e-cac')->sole();
        $primeiroCarimbo = $monitoring->source_at;

        // `encerrado` é decisão que nenhum serviço deste conjunto devolve:
        // uma projeção nova reescreve os dados e deixa o estado em pé.
        $monitoring->forceFill(['state' => 'encerrado'])->save();

        $outraExecucao = SerproSyncRun::factory()->create([
            'account_id' => $account->getKey(),
            'obligations' => ['caixas-postais/e-cac'],
        ]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $outraExecucao->getKey(),
            'client_id' => $client->getKey(),
        ]);

        // Ainda dentro da validade do termo (`token_expires_at` é a meia-noite
        // de 29/09): a segunda execução precisa chamar de verdade.
        CarbonImmutable::setTestNow('2026-09-28 18:00:00');

        $outroJob = new SyncSerproClientJob($outraExecucao->getKey(), $account->getKey(), $client->getKey());
        $outroJob->handle();
        $outroJob->handle();

        $this->assertSame(1, SerproMonitoring::query()
            ->where('obligation', 'caixas-postais/e-cac')->count());

        $regravado = $monitoring->refresh();
        $this->assertSame('encerrado', $regravado->state);
        $this->assertTrue($regravado->source_at->gt($primeiroCarimbo));
    }

    public function test_a_resposta_com_dado_limpa_a_causa_de_inelegibilidade_anterior(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel(['caixas-postais/e-cac']);
        Http::fake($this->fakesDeSucesso());

        // A linha nasceu numa execução em que a outorga faltava; a execução
        // que agora consegue chamar apaga a marcação junto com os dados.
        $semOutorga = new SerproMonitoring;
        $semOutorga->forceFill([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'caixas-postais/e-cac',
            'cause' => 'sem_procuracao',
        ])->save();

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->handle();
        $job->handle();

        $this->assertNull(SerproMonitoring::query()
            ->where('obligation', 'caixas-postais/e-cac')->sole()->cause);
    }

    public function test_o_pgdas_consolida_o_periodo_com_a_ultima_transmissao_e_o_das(): void
    {
        $result = new SerproResult(200, json_decode(
            '{"anocalendario":2018,"periodos":[{"periodoApuracao":201801,"operacoes":['
            .'{"tipoOperacao":"Original","indiceDeclaracao":{"numeroDeclaracao":"00000000201801001","dataHoraTransmissao":"20220331152512","malha":""},"indiceDas":null},'
            .'{"tipoOperacao":"Declaração Retificadora","indiceDeclaracao":{"numeroDeclaracao":"00000000201801002","dataHoraTransmissao":"20220401101010","malha":null},"indiceDas":null},'
            .'{"tipoOperacao":"Geração de DAS","indiceDeclaracao":null,"indiceDas":{"numeroDas":"07202215764027873","datahoraEmissaoDas":"20220606153456","dasPago":false}}]}]}',
            true,
        ), [], 'resp-1', 'tag-de-32-caracteres-para-o-teste');

        $projecao = resolve(SerproMonitoringMapper::class)->project('CONSDECLARACAO13', $result);

        $this->assertSame('00000000201801002', $projecao['fields']['gi_declaracao']);
        $this->assertNull($projecao['fields']['due_on'] ?? null);
        $this->assertSame([
            [
                'period' => '2018-01',
                'declared_at' => '2022-04-01 10:10:10',
                'rectified' => true,
                'slip_number' => '07202215764027873',
                'slip_issued_at' => '2022-06-06 15:34:56',
                'due_on' => null,
                'slip_paid' => false,
            ],
        ], $projecao['periods']);
    }

    public function test_o_pgdas_sem_periodos_marca_a_causa_e_nao_os_dados(): void
    {
        $result = new SerproResult(200, ['anocalendario' => 2018, 'periodos' => []], [], 'resp-1', 'tag-de-32-caracteres-para-o-teste');

        $projecao = resolve(SerproMonitoringMapper::class)->project('CONSDECLARACAO13', $result);

        $this->assertSame('sem_declaracao', $projecao['cause']);
        $this->assertSame([], $projecao['periods']);
    }

    public function test_o_regime_expoe_a_opcao_e_descarta_o_documento_base64(): void
    {
        $result = new SerproResult(200, json_decode(
            '{"cnpjMatriz":"33683111000108","anoCalendario":2026,"regimeEscolhido":"COMPETENCIA","dataHoraOpcao":20251220025818,"demonstrativoPdf":"JVBERi0xLjcK","textoResolucao":"UkVTT0xVQ0FP"}',
            true,
        ), [], 'resp-1', 'tag-de-32-caracteres-para-o-teste');

        $projecao = resolve(SerproMonitoringMapper::class)->project('CONSULTAROPCAOREGIME103', $result);

        $this->assertSame([
            'regime_escolhido' => 'COMPETENCIA',
            'data_da_opcao' => '2025-12-20 02:58:18',
        ], $projecao['fields']);

        // O demonstrativo e a resolução são documento em base64: a projeção
        // não tem chave para eles, e a linha não pode carregar PDF algum.
        $serializado = json_encode($projecao);
        $this->assertStringNotContainsString('JVBERi0', $serializado);
        $this->assertStringNotContainsString('demonstrativo', $serializado);
    }

    public function test_o_registro_da_falha_guarda_codigo_e_tag_sem_mensagens(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();

        try {
            resolve(SerproCallRecorder::class)->record(
                $run->getKey(),
                $account->getKey(),
                $client->getKey(),
                'REGIMEAPURACAO',
                'CONSULTAROPCAOREGIME103',
                fn () => throw new SerproException(
                    'Acesso negado.',
                    SerproFailure::DoNotRetry,
                    403,
                    'AcessoNegado-ICGERENCIADOR-022',
                    'resp-022',
                    'tag-qualquer-de-teste-32-chars-ok',
                ),
            );
            $this->fail('A exceção tinha de subir depois de registrada.');
        } catch (SerproException) {
            // O recorder registra e repassa; quem sabe classificar é o job.
        }

        $call = SerproCall::sole();
        $this->assertSame('CONSULTAROPCAOREGIME103', $call->id_servico);
        $this->assertSame(SerproFailure::DoNotRetry, $call->status);
        $this->assertSame('AcessoNegado-ICGERENCIADOR-022', $call->provider_code);
        $this->assertSame('resp-022', $call->response_id);
        $this->assertNull($call->messages);
    }

    public function test_a_chamada_de_conta_sem_cliente_e_a_gratuita_sem_cobranca(): void
    {
        [$account, , $run] = $this->cenarioChamavel();

        $result = new SerproResult(200, ['protocolo' => 'x'], [], 'resp-91', 'tag-de-32-caracteres-para-o-teste');

        resolve(SerproCallRecorder::class)->record(
            $run->getKey(),
            $account->getKey(),
            null,
            'SITFIS',
            'SOLICITARPROTOCOLO91',
            fn () => $result,
        );

        $call = SerproCall::sole();
        $this->assertNull($call->client_id);
        $this->assertSame($run->getKey(), $call->run_id);
        $this->assertFalse($call->billable);
        $this->assertSame('Apoiar', $call->path);
    }

    public function test_a_auditoria_nao_tem_onde_guardar_o_payload(): void
    {
        // A coluna não existe de propósito: `dados` carrega o documento do
        // cliente dentro do envelope, e o registro precisa da tag e da
        // duração — a prova da chamada, e não o conteúdo dela.
        $this->assertFalse(Schema::hasColumn('serpro_calls', 'dados'));
        $this->assertFalse(Schema::hasColumn('serpro_calls', 'payload'));
    }

    /**
     * Conta pronta para a fila: integração ligada, certificado, termo
     * vigente, token em cache e um PJ com item aberto na execução.
     *
     * @param  list<string>|null  $obrigacoes  Escopo da run; `null` sincroniza todas as habilitadas.
     * @return array{0: Account, 1: Client, 2: SerproSyncRun}
     */
    private function cenarioChamavel(?array $obrigacoes = null): array
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

        SerproConnection::factory()->create([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
            'certificate_encrypted' => Crypt::encryptString($this->pfxDaPlataforma()),
            'certificate_password_encrypted' => Crypt::encryptString('senha'),
            'contratante_numero' => '12345678000195',
            'contratante_tipo' => 2,
            'certificate_valid_until' => now()->addYear(),
        ]);

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

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $run = SerproSyncRun::factory()->create([
            'account_id' => $account->getKey(),
            'obligations' => $obrigacoes,
        ]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return [$account, $client, $run];
    }

    /**
     * O oráculo concede a família `00006` e a caixa postal responde com a
     * forma que o exemplo publicado do provedor documenta: `conteudo` com
     * um objeto que traz `listaMensagens`.
     *
     * @return array<string, mixed>
     */
    private function fakesDeSucesso(): array
    {
        $oracle = '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":["Caixa Postal - Mensagens"]}]';

        $caixaPostal = json_encode([
            'codigo' => '00',
            'conteudo' => [[
                'indicadorUltimaPagina' => 'S',
                'quantidadeMensagens' => '4',
                'listaMensagens' => [
                    [
                        'isn' => '0001626772',
                        'assuntoModelo' => 'Notificação nº ++VARIAVEL++',
                        'valorParametroAssunto' => '00160556',
                        'dataEnvio' => '20260920',
                        'horaEnvio' => '092949',
                        'indicadorLeitura' => '0',
                        'dataLeitura' => '',
                        'horaLeitura' => '',
                        'dataCiencia' => '20260921',
                        'dataValidade' => '20261005',
                    ],
                    [
                        'isn' => '0001626771',
                        'assuntoModelo' => 'Aviso de edital eletrônico',
                        'valorParametroAssunto' => '',
                        'dataEnvio' => '20260910',
                        'horaEnvio' => '223135',
                        'indicadorLeitura' => '1',
                        'dataLeitura' => '20260911',
                        'horaLeitura' => '083005',
                        'dataCiencia' => '20260911',
                        'dataValidade' => '',
                    ],
                    [
                        'isn' => '0001626770',
                        'assuntoModelo' => 'FGTS Digital — guia disponível',
                        'valorParametroAssunto' => '',
                        'dataEnvio' => '20260915',
                        'horaEnvio' => '120000',
                        'indicadorLeitura' => '0',
                        'dataLeitura' => '',
                        'horaLeitura' => '',
                        'dataCiencia' => '',
                        'dataValidade' => '',
                    ],
                    [
                        'isn' => '0001626769',
                        'assuntoModelo' => 'Comunicação DET — prazo',
                        'valorParametroAssunto' => '',
                        'dataEnvio' => '20260905',
                        'horaEnvio' => '090000',
                        'indicadorLeitura' => '1',
                        'dataLeitura' => '20260906',
                        'horaLeitura' => '100000',
                        'dataCiencia' => '20260906',
                        'dataValidade' => '20260930',
                    ],
                ],
            ]],
        ]);

        return [
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => function ($request) use ($oracle, $caixaPostal) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                $dados = match ($servico) {
                    'OBTERPROCURACAO41' => $oracle,
                    'PAGAMENTOS71' => '[]',
                    default => $caixaPostal,
                };

                return Http::response([
                    'status' => 200,
                    'dados' => $dados,
                    'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Requisição efetuada com sucesso']],
                    'responseId' => 'resp-'.$servico,
                ]);
            },
            '*' => Http::response([
                'status' => 200,
                'dados' => '{}',
                'mensagens' => [],
                'responseId' => 'resp-2',
            ]),
        ];
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
