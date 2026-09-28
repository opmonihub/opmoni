<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalStage;
use App\Http\Controllers\Tenant\FiscalDocumentController;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Fiscal\Read\FiscalCoverage;
use App\Services\Fiscal\Read\FiscalDocuments;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ClientCertificateFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A primeira superfície de leitura da captura: o resumo que responde como a
 * carteira está e o que precisa de alguém.
 */
class FiscalDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
        Storage::fake('certificates');
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
    }

    public function test_resumo_exige_sessao(): void
    {
        $account = Account::factory()->create();
        Client::factory()->count(2)->create(['account_id' => $account->getKey()]);

        $this->getJson('/api/fiscal/summary')
            ->assertUnauthorized()
            ->assertJsonMissingPath('data.coverage');
    }

    public function test_resumo_agrupa_atencao_por_certificado(): void
    {
        $account = Account::factory()->create();

        // Os nomes são numerados porque a lista de atenção sai em ordem alfabética
        // de cliente, e é essa ordem que o teste precisa fixar.
        $semCertificado = $this->cliente($account, 'Cliente 1 Sem certificado');
        $vencido = $this->cliente($account, 'Cliente 2 Certificado vencido');
        $semSenha = $this->cliente($account, 'Cliente 3 Sem senha guardada');
        $valido = $this->cliente($account, 'Cliente 4 Certificado válido');

        // Vencido e sem senha ao mesmo tempo: quem decide é a validade, porque
        // reenviar o certificado é o mesmo conserto para os dois defeitos e a
        // validade vencida é a que não se resolve sozinha.
        $this->fabricaDeCertificado($vencido)->state(['valid_until' => now()->subDay()])->withoutPassword()->create();
        $this->fabricaDeCertificado($semSenha)->withoutPassword()->create();
        $this->fabricaDeCertificado($valido)->withPassword()->create();

        // `user` lê: a cobertura é leitura e não há por que fechar para quem só
        // pode ler.
        $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 4)
            ->assertJsonPath('data.coverage.capturable', 1)
            ->assertJsonPath('data.coverage.not_capturable', 3)
            ->assertJsonCount(3, 'data.attention')
            ->assertJsonPath('data.attention.0.client_id', $semCertificado->getKey())
            ->assertJsonPath('data.attention.0.client_name', $semCertificado->name)
            ->assertJsonPath('data.attention.0.reason', 'certificate_absent')
            ->assertJsonPath('data.attention.1.client_id', $vencido->getKey())
            ->assertJsonPath('data.attention.1.reason', 'certificate_expired')
            ->assertJsonPath('data.attention.2.client_id', $semSenha->getKey())
            ->assertJsonPath('data.attention.2.reason', 'certificate_password_missing')
            ->assertJsonPath('data.attention.2.blocked_until', null);

        // O cliente com certificado aproveitável não está em atenção nenhuma.
        $this->assertNotContains(
            $valido->getKey(),
            array_column($this->resumo($account)['attention'], 'client_id')
        );
    }

    public function test_resumo_acumula_atencao_operacional_sem_descontar_cobertura(): void
    {
        $account = Account::factory()->create();
        $bloqueado = $this->clienteComCertificado($account, 'Cliente 1 Bloqueado');
        $interrompido = $this->clienteComCertificado($account, 'Cliente 2 Interrompido');

        $ate = now()->addMinutes(30);
        $this->cursor($bloqueado, [
            'last_run_at' => now(),
            'last_seen_at' => now(),
            'last_error' => 'blocked_consumption',
            'blocked_until' => $ate,
        ]);
        $this->cursor($interrompido, [
            'last_run_at' => now(),
            'last_seen_at' => now()->subDays((int) config('fiscal.continuity_days') + 1),
        ]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            // Os dois clientes continuam capturáveis: o certificado é o que
            // decide a cobertura, e a operação parada é outro eixo.
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 2)
            ->assertJsonPath('data.coverage.capturable', 2)
            ->assertJsonPath('data.coverage.not_capturable', 0)
            ->assertJsonCount(2, 'data.attention')
            ->assertJsonPath('data.attention.0.reason', 'capture_blocked')
            ->assertJsonPath('data.attention.0.client_id', $bloqueado->getKey())
            ->assertJsonPath('data.attention.0.blocked_until', $ate->toISOString())
            ->assertJsonPath('data.attention.1.reason', 'history_interrupted')
            ->assertJsonPath('data.attention.1.client_id', $interrompido->getKey())
            ->assertJsonPath('data.attention.1.blocked_until', null);
    }

    public function test_resumo_avisa_continuidade_antes_de_interromper(): void
    {
        $account = Account::factory()->create();
        $naBanda = $this->clienteComCertificado($account, 'Cliente 1 Na Banda');
        $emDia = $this->clienteComCertificado($account, 'Cliente 2 Em Dia');

        $this->cursor($naBanda, [
            'last_run_at' => now(),
            'last_seen_at' => now()->subDays((int) config('fiscal.continuity_alert_days') + 5),
        ]);
        $this->cursor($emDia, [
            'last_run_at' => now(),
            'last_seen_at' => now()->subDays(10),
        ]);

        $this->actingAs($this->membroDe($account, 'admin'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.capturable', 2)
            ->assertJsonCount(1, 'data.attention')
            ->assertJsonPath('data.attention.0.client_id', $naBanda->getKey())
            ->assertJsonPath('data.attention.0.reason', 'continuity_warning');
    }

    public function test_resumo_nao_trata_esfriamento_rotineiro_como_alerta(): void
    {
        $account = Account::factory()->create();

        // O `137` esfria a consulta de um cliente saudável por uma hora a cada
        // consulta. A parada no tempo é normal; só a marca de consumo indevido é
        // news.
        $esfriando = $this->clienteComCertificado($account, 'Cliente 1 Esfriando');
        $this->cursor($esfriando, [
            'last_run_at' => now(),
            'last_seen_at' => now(),
            'blocked_until' => now()->addHour(),
        ]);

        // E o inverso: consumo indevido cuja janela já passou não é mais nada
        // para o operador fazer hoje.
        $bloqueioVencido = $this->clienteComCertificado($account, 'Cliente 2 Bloqueio Vencido');
        $this->cursor($bloqueioVencido, [
            'last_run_at' => now(),
            'last_seen_at' => now(),
            'last_error' => 'blocked_consumption',
            'blocked_until' => now()->subHour(),
        ]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.capturable', 2)
            ->assertJsonPath('data.attention', []);
    }

    public function test_resumo_marca_certificado_que_precisa_reenvio(): void
    {
        $account = Account::factory()->create();
        $reenvio = $this->clienteComCertificado($account, 'Cliente 1 Reenvio');

        // A captura é quem descobre que a senha guardada não abre, e ela deixa a
        // marca na coluna do cursor. A leitura não abre a senha: ela confere a
        // marca.
        $this->cursor($reenvio, [
            'last_run_at' => now(),
            'last_seen_at' => now(),
            'last_error' => 'certificate_reupload',
        ]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 1)
            ->assertJsonPath('data.coverage.capturable', 0)
            ->assertJsonPath('data.coverage.not_capturable', 1)
            ->assertJsonPath('data.attention.0.reason', 'certificate_reupload');
    }

    public function test_resumo_marca_posicao_abandonada(): void
    {
        $account = Account::factory()->create();
        $abandonada = $this->clienteComCertificado($account, 'Cliente 1 Posicao Abandonada');

        $this->cursor($abandonada, [
            'last_run_at' => now(),
            'last_seen_at' => now(),
            'last_error' => 'gap_abandoned',
        ]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            // A posição perdida não tira o cliente da cobertura: o certificado
            // continua servindo, e é por isso que os dois eixos são separados.
            ->assertOk()
            ->assertJsonPath('data.coverage.capturable', 1)
            ->assertJsonCount(1, 'data.attention')
            ->assertJsonPath('data.attention.0.reason', 'gap_abandoned');
    }

    public function test_resumo_classifica_falha_de_captura(): void
    {
        $account = Account::factory()->create();
        $falhou = $this->clienteComCertificado($account, 'Cliente 1 Falhou');
        $normal = $this->clienteComCertificado($account, 'Cliente 2 Normal');

        // A frase é a que a captura grava: nome da classe, classificação do
        // conector e a frase fixa. Nenhuma parte disso é segredo.
        $this->cursor($falhou, [
            'last_run_at' => now(),
            'last_error' => 'FiscalException [upstream] — falha na consulta ao serviço de distribuição.',
        ]);
        $this->cursor($normal, ['last_run_at' => now()]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.capturable', 2)
            ->assertJsonCount(1, 'data.attention')
            ->assertJsonPath('data.attention.0.client_id', $falhou->getKey())
            ->assertJsonPath('data.attention.0.reason', 'capture_failed');
    }

    public function test_resumo_nao_decifra_senha_para_contar_cobertura(): void
    {
        $account = Account::factory()->create();

        // Cifra que não abre: `APP_KEY` rotacionada, valor truncado, lixo antigo.
        // Para a leitura o certificado tem senha, e um certificado com senha é
        // capturável; quem descobre que a senha não abre é a captura, e a prova
        // é a marca que ela deixa no cursor. Se este teste passar com a coluna
        // indecifrável e o cliente capturável, a leitura não decifrou nada.
        $indecifravel = $this->cliente($account, 'Cliente 1 Cifra Indecifravel');
        $this->fabricaDeCertificado($indecifravel)
            ->state(['password_encrypted' => base64_encode('senha-quebrada')])
            ->create();

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 1)
            ->assertJsonPath('data.coverage.capturable', 1)
            ->assertJsonPath('data.coverage.not_capturable', 0)
            ->assertJsonPath('data.attention', []);
    }

    public function test_resumo_conta_documentos_por_modelo_e_mes(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->clienteComCertificado($account, 'Cliente 1 Com Documentos');

        $this->documento($cliente, ['model' => FiscalModel::Nfe, 'emissao_at' => '2026-08-04 10:00:00']);
        $this->documento($cliente, ['model' => FiscalModel::Nfe, 'emissao_at' => '2026-08-20 10:00:00']);
        $this->documento($cliente, ['model' => FiscalModel::Nfe, 'emissao_at' => '2026-09-02 10:00:00']);
        $this->documento($cliente, ['model' => FiscalModel::Cte, 'emissao_at' => '2026-09-11 10:00:00']);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.documents.total', 4)
            ->assertJsonPath('data.documents.models', ['cte' => 1, 'nfe' => 3])
            ->assertJsonPath('data.documents.over_time', [
                ['month' => '2026-08', 'total' => 2],
                ['month' => '2026-09', 'total' => 2],
            ]);
    }

    public function test_resumo_sem_documentos_nao_inventa_medida(): void
    {
        $account = Account::factory()->create();
        $this->clienteComCertificado($account, 'Cliente 1 Sem Documento');

        // Sem linha de `fiscal_documents` não há o que medir: o resumo diz zero
        // e não preenche a série com um mês que ninguém emitiu nada.
        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.documents.total', 0)
            ->assertJsonPath('data.documents.models', [])
            ->assertJsonPath('data.documents.over_time', [])
            ->assertJsonPath('data.last_capture', null)
            ->assertJsonPath('data.attention', []);
    }

    public function test_resumo_releta_ultima_captura_da_conta(): void
    {
        $account = Account::factory()->create();
        $antigo = $this->clienteComCertificado($account, 'Cliente 1 Antigo');
        $recente = $this->clienteComCertificado($account, 'Cliente 2 Recente');
        $semConsulta = $this->clienteComCertificado($account, 'Cliente 3 Nunca Consultado');

        $this->cursor($antigo, ['last_run_at' => '2026-09-20 03:00:00']);
        $cursorRecente = $this->cursor($recente, [
            'last_run_at' => '2026-09-27 10:00:00',
            'last_error' => 'lote incompleto: 1 de 2 posições não gravadas.',
        ]);
        $this->cursor($semConsulta);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.last_capture.source', 'nfe_distribuicao')
            ->assertJsonPath('data.last_capture.ran_at', $cursorRecente->last_run_at->toISOString())
            ->assertJsonPath('data.last_capture.error', 'lote incompleto: 1 de 2 posições não gravadas.');
    }

    public function test_resumo_nao_expoe_dados_de_outra_conta(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->clienteComCertificado($account, 'Cliente 1 Da Conta Própria');
        $semCertificado = $this->cliente($account, 'Cliente 2 Da Conta Própria');
        $this->documento($cliente, ['model' => FiscalModel::Nfe, 'emissao_at' => '2026-09-10 10:00:00']);
        $this->cursor($cliente, ['last_run_at' => '2026-09-27 10:00:00']);

        $outra = Account::factory()->create();
        $clienteAlheio = $this->clienteComCertificado($outra, 'Empresa Alienada Com Certificado');
        $this->cliente($outra, 'Empresa Alienada Sem Certificado');
        $this->documento($clienteAlheio, ['model' => FiscalModel::Cte, 'emissao_at' => '2026-01-10 10:00:00']);
        // O cursor mais recente de toda a base é o da outra conta: se a consulta
        // não fosse da conta, ele apareceria como última captura.
        $this->cursor($clienteAlheio, ['last_run_at' => '2026-09-28 11:00:00']);

        $response = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary');

        $response->assertOk()
            ->assertJsonPath('data.coverage.total', 2)
            ->assertJsonPath('data.coverage.capturable', 1)
            ->assertJsonCount(1, 'data.attention')
            ->assertJsonPath('data.attention.0.client_id', $semCertificado->getKey())
            ->assertJsonPath('data.documents.total', 1)
            ->assertJsonPath('data.documents.models', ['nfe' => 1])
            ->assertJsonPath('data.documents.over_time', [['month' => '2026-09', 'total' => 1]])
            ->assertJsonPath('data.last_capture.ran_at', '2026-09-27T10:00:00.000000Z');

        // Nenhum nome, contagem ou caminho da outra conta no corpo inteiro: nem
        // como valor, nem como substring.
        $response->assertDontSee('Alienada', false)
            ->assertDontSee('cte', false)
            ->assertDontSee('2026-01', false);
    }

    public function test_resumo_recusa_membro_de_outra_conta(): void
    {
        $account = Account::factory()->create();
        $this->cliente($account, 'Cliente Da Conta Própria');

        $outra = Account::factory()->create();
        $this->cliente($outra, 'Empresa Alienada Sem Certificado');

        // A conta corrente aponta para onde o usuário não pertence: a resposta
        // é negativa e não descreve nada da conta alheia.
        $visitante = User::factory()->create();
        $visitante->forceFill(['current_account_id' => $outra->getKey()])->save();

        $this->actingAs($visitante, 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertForbidden()
            ->assertDontSee('Alienada', false)
            ->assertDontSee('coverage', false);
    }

    public function test_resumo_ignora_cliente_removido_logicamente(): void
    {
        $account = Account::factory()->create();
        $this->clienteComCertificado($account, 'Cliente 1 Ativo');

        // Removido por logicamente continua tendo documento guardado, mas não é
        // capturado: contá-lo faria a cobertura cair por um cliente que ninguém
        // mais espera ver chegar. O nome dele também não pode aparecer na
        // atenção, porque para o painel ele não existe mais como cliente.
        $removido = $this->clienteComCertificado($account, 'Cliente 2 Removido');
        $removido->delete();

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 1)
            ->assertJsonPath('data.coverage.capturable', 1)
            ->assertJsonPath('data.attention', [])
            ->assertDontSee('Removido', false);
    }

    public function test_gate_de_leitura_aceita_membro_e_recusa_visitante(): void
    {
        $account = Account::factory()->create();
        $outra = Account::factory()->create();

        // O middleware `tenant` já recusa o visitante antes do controller, então
        // exercitar o Gate direto é o que prova que a policy está registrada e
        // que o gate não virou enfeite: um `Gate::authorize` removido passaria
        // no teste de rota e falharia aqui.
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        foreach (['admin', 'operador', 'user'] as $papel) {
            $this->assertTrue(
                Gate::forUser($this->membroDe($account, $papel))->allows('viewAny', FiscalDocument::class),
                "o papel {$papel} precisa ler o painel fiscal"
            );
        }

        $this->assertFalse(
            Gate::forUser($this->membroDe($outra, 'admin'))->allows('viewAny', FiscalDocument::class)
        );

        // A conta corrente é a da conta alheia e o usuário não é membro dela: o
        // mesmo caminho que o middleware usa, sem o middleware.
        $visitante = User::factory()->create();
        $visitante->forceFill(['current_account_id' => $outra->getKey()])->save();
        resolve(CurrentTenant::class)->accountId = $outra->getKey();

        $this->assertFalse(Gate::forUser($visitante)->allows('viewAny', FiscalDocument::class));
    }

    public function test_controller_autoriza_a_si_mesmo(): void
    {
        $outra = Account::factory()->create();
        $this->cliente($outra, 'Empresa Alienada Sem Certificado');

        $visitante = User::factory()->create();
        $visitante->forceFill(['current_account_id' => $outra->getKey()])->save();

        $this->actingAs($visitante, 'sanctum');
        resolve(CurrentTenant::class)->accountId = $outra->getKey();

        // O controller chamado fora do middleware `tenant`: é a única forma de
        // ver o `Gate::authorize` do próprio controller, que na rota é
        // precedido pelo 403 do middleware e por isso não apareceria em teste
        // de rota nenhum. A policy precisa recusar aqui também, ou o endpoint
        // passaria a depender de uma camada só.
        $this->expectException(AuthorizationException::class);

        (new FiscalDocumentController)->summary(app(FiscalCoverage::class));
    }

    public function test_resumo_devolve_apenas_as_chaves_declaradas(): void
    {
        $account = Account::factory()->create();
        $bloqueado = $this->clienteComCertificado($account, 'Cliente 1 Bloqueado');
        $certificado = ClientCertificate::query()->where('client_id', $bloqueado->getKey())->sole();
        $this->documento($bloqueado, ['model' => FiscalModel::Nfe, 'emissao_at' => '2026-09-10 10:00:00']);
        $this->cursor($bloqueado, [
            'last_run_at' => '2026-09-27 10:00:00',
            'last_error' => 'blocked_consumption',
            'blocked_until' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertSame(['coverage', 'attention', 'documents', 'last_capture'], array_keys($data));
        $this->assertSame(['total', 'capturable', 'not_capturable'], array_keys($data['coverage']));
        $this->assertSame(['total', 'models', 'over_time'], array_keys($data['documents']));
        $this->assertSame(['source', 'ran_at', 'error'], array_keys($data['last_capture']));
        $this->assertSame(
            ['client_id', 'client_name', 'reason', 'blocked_until'],
            array_keys($data['attention'][0])
        );

        // Nem a senha cifrada, nem o caminho do arquivo, nem o titular do
        // certificado: o resumo descreve a carteira, não o cofre.
        $response->assertDontSee('password_encrypted', false)
            ->assertDontSee('storage_path', false)
            ->assertDontSee('sha256', false)
            ->assertDontSee((string) $certificado->storage_path, false)
            ->assertDontSee((string) $certificado->subject, false);
    }

    public function test_lista_exige_sessao(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente Da Conta Própria');
        $this->documento($cliente);

        $this->getJson('/api/fiscal/documents')
            ->assertUnauthorized()
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('available_models');
    }

    public function test_lista_abre_para_qualquer_membro_da_conta(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente Da Conta Própria');
        $this->documento($cliente);

        // `user` só não escreve. Fechar a leitura para ele deixaria o painel
        // fiscal como a única tela do produto que o membro read-only não alcança.
        foreach (['admin', 'operador', 'user'] as $papel) {
            $this->actingAs($this->membroDe($account, $papel), 'sanctum')
                ->getJson('/api/fiscal/documents')
                ->assertOk()
                ->assertJsonPath('meta.total', 1);
        }
    }

    public function test_lista_ordena_pela_emissao_mais_recente_primeiro(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Documentos');

        $antigo = $this->documento($cliente, ['emissao_at' => '2026-08-01 09:00:00']);
        $meio = $this->documento($cliente, ['emissao_at' => '2026-09-10 09:00:00']);
        $novo = $this->documento($cliente, ['emissao_at' => '2026-09-20 09:00:00']);

        // Empate de emissão: o desempate é o id, para que a segunda página não
        // traga de volta a linha da primeira.
        $empateAntigo = $this->documento($cliente, ['emissao_at' => '2026-09-10 09:00:00']);
        $empateNovo = $this->documento($cliente, ['emissao_at' => '2026-09-10 09:00:00']);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.id', $novo->getKey())
            ->assertJsonPath('data.1.id', $empateNovo->getKey())
            ->assertJsonPath('data.2.id', $empateAntigo->getKey())
            ->assertJsonPath('data.3.id', $meio->getKey())
            ->assertJsonPath('data.4.id', $antigo->getKey());
    }

    public function test_lista_ordena_por_valor_e_por_captura_quando_pedido(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Documentos');

        $barato = $this->documento($cliente, [
            'valor_total' => 10.00,
            'captured_at' => '2026-09-01 10:00:00',
            'emissao_at' => '2026-09-01 10:00:00',
        ]);
        $caro = $this->documento($cliente, [
            'valor_total' => 900.00,
            'captured_at' => '2026-09-20 10:00:00',
            'emissao_at' => '2026-09-20 10:00:00',
        ]);

        $membro = $this->membroDe($account, 'operador');

        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/documents?sort=valor_total&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $barato->getKey())
            ->assertJsonPath('data.1.id', $caro->getKey());

        // Sem direção, o padrão é decrescente: a mesma leitura do padrão de
        // emissão, que é o que a tela põe na frente.
        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/documents?sort=valor_total')
            ->assertOk()
            ->assertJsonPath('data.0.id', $caro->getKey());

        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/documents?sort=captured_at&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $barato->getKey());
    }

    public function test_lista_conta_eventos_da_chave_de_acesso_e_nao_por_nsu(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Eventos');
        $outro = $this->cliente($account, 'Cliente 2 Sem Eventos');

        $documento = $this->documento($cliente, ['nsu' => 1000, 'emissao_at' => '2026-09-10 10:00:00']);

        // Cada evento chega na distribuição com a posição própria, e é por isso
        // que a contagem não pode ser por NSU: os dois eventos abaixo têm NSU
        // diferente do documento e um do outro, e ainda assim são dele.
        $primeiro = $this->evento($cliente, $documento->chave_acesso, '110111', 2001);
        $segundo = $this->evento($cliente, $documento->chave_acesso, '110112', 2002);
        $semEvento = $this->documento($outro);

        // A mesma chave de acesso em outro cliente da carteira é a outra metade
        // do par, e a unicidade da tabela é `(client_id, chave_acesso, stage,
        // event_id)`: uma nota emitida a um e recebida pelo outro aparece nas
        // duas carteiras. Contar por chave sozinha somaria os dois documentos
        // e diria que o primeiro tem três eventos.
        $mesmaChave = $this->documento($outro, [
            'chave_acesso' => $documento->chave_acesso,
            'emissao_at' => '2026-09-11 10:00:00',
        ]);
        $this->evento($outro, $documento->chave_acesso, '110111', 3001);

        $linhas = collect(
            $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
                ->getJson('/api/fiscal/documents')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertSame(2, $linhas[$documento->getKey()]['event_count']);
        $this->assertSame(1, $linhas[$mesmaChave->getKey()]['event_count']);

        // Uma linha de evento não conta a si mesma: ela já está na lista, e
        // somá-la diria que existe um evento a mais do que existe.
        $this->assertSame(1, $linhas[$primeiro->getKey()]['event_count']);
        $this->assertSame(1, $linhas[$segundo->getKey()]['event_count']);

        // Documento sem evento é zero explícito, e não campo vazio: a tela
        // escreve "sem eventos" a partir do número.
        $this->assertSame(0, $linhas[$semEvento->getKey()]['event_count']);
    }

    public function test_lista_devolve_a_identidade_do_cliente_removido_sem_credencial(): void
    {
        $account = Account::factory()->create();
        $removido = $this->clienteComCertificado($account, 'Cliente Removido Com Certificado');
        $documento = $this->documento($removido, ['emissao_at' => '2026-09-10 10:00:00']);
        $certificado = ClientCertificate::query()->where('client_id', $removido->getKey())->sole();
        $removido->delete();

        $response = $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/documents');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $documento->getKey())
            ->assertJsonPath('data.0.client.id', $removido->getKey())
            ->assertJsonPath('data.0.client.name', 'Cliente Removido Com Certificado')
            ->assertJsonPath('data.0.client.tax_id', $removido->tax_id);

        // A identidade que sobra da remoção é nome e CNPJ. Certificado é
        // material de outro cofre, e a linha não tem por que carregá-lo.
        $response->assertDontSee('password_encrypted', false)
            ->assertDontSee('storage_path', false)
            ->assertDontSee((string) $certificado->storage_path, false)
            ->assertDontSee((string) $certificado->subject, false);
    }

    public function test_lista_filtra_por_modelo_cliente_partes_de_cnpj_tipo_e_periodo(): void
    {
        $account = Account::factory()->create();
        $primeiro = $this->cliente($account, 'Cliente 1 Emitente');
        $segundo = $this->cliente($account, 'Cliente 2 Destinatario');

        $alvo = $this->documento($primeiro, [
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'emitente_cnpj' => '11111111000199',
            'destinatario_cnpj' => '33333333000188',
            'valor_total' => 55.55,
            'emissao_at' => '2026-09-10 10:00:00',
        ]);
        $cte = $this->documento($primeiro, [
            'model' => FiscalModel::Cte,
            'emitente_cnpj' => '11111111000199',
            'valor_total' => 900.00,
            'emissao_at' => '2026-09-10 10:00:00',
        ]);
        $notaDoSegundo = $this->documento($segundo, [
            'model' => FiscalModel::Nfe,
            'emitente_cnpj' => '22222222000177',
            'destinatario_cnpj' => '44444444000166',
            'valor_total' => 70.00,
            'emissao_at' => '2026-08-10 10:00:00',
        ]);
        $evento = $this->evento($primeiro, $alvo->chave_acesso, '110111', 2001, [
            'emitente_cnpj' => '11111111000199',
            'destinatario_cnpj' => '33333333000188',
            'valor_total' => 55.55,
            'emissao_at' => '2026-09-10 10:00:00',
        ]);

        $membro = $this->membroDe($account, 'operador');

        // Os ids saem ordenados porque o que estes testes medem é *quais* linhas
        // o filtro deixou passar. A ordem da página tem teste próprio
        // (`test_lista_ordena_*`) e repetir a mesma expectativa em cada filtro
        // só esconderia uma mudança de ordenação atrás de um filtro.
        $ids = fn (string $query): array => collect(
            $this->actingAs($membro, 'sanctum')
                ->getJson('/api/fiscal/documents?'.$query)
                ->assertOk()
                ->json('data')
        )->pluck('id')->sort()->values()->all();

        // Um filtro por vez, cada um cortando do jeito que o rótulo promete. O
        // CNPJ entra por prefixo, porque é assim que o operador o digita. O
        // evento entra em `model[]=nfe` porque é uma entrega de NF-e: o modelo
        // é do documento, e o que separa a linha do evento é a etapa.
        $this->assertSame([$alvo->getKey(), $notaDoSegundo->getKey(), $evento->getKey()], $ids('model[]=nfe'));
        $this->assertSame([$alvo->getKey(), $cte->getKey(), $evento->getKey()], $ids('client_id='.$primeiro->getKey()));
        $this->assertSame([$alvo->getKey(), $cte->getKey(), $evento->getKey()], $ids('issuer=1111'));
        $this->assertSame([$notaDoSegundo->getKey()], $ids('recipient=4444'));
        $this->assertSame([$evento->getKey()], $ids('kind=event'));
        $this->assertSame([$alvo->getKey(), $cte->getKey(), $evento->getKey()], $ids('issued_from=2026-09-01&issued_to=2026-09-30'));
        $this->assertSame([$notaDoSegundo->getKey()], $ids('issued_to=2026-08-31'));

        // E a combinação inteira, que é como a tela usa: cada filtro sozinho
        // deixa passar mais de uma linha, e é a soma deles que escolhe uma.
        $this->assertSame(
            [$alvo->getKey()],
            $ids('model[]=nfe&client_id='.$primeiro->getKey().'&issuer=1111&recipient=3333&kind=document'
                .'&issued_from=2026-09-01&issued_to=2026-09-30&amount_min=10&amount_max=100')
        );
    }

    public function test_lista_filtra_por_faixa_de_valor(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Documentos');

        $barato = $this->documento($cliente, ['valor_total' => 10.00, 'emissao_at' => '2026-09-01 10:00:00']);
        $medio = $this->documento($cliente, ['valor_total' => 50.00, 'emissao_at' => '2026-09-10 10:00:00']);
        $caro = $this->documento($cliente, ['valor_total' => 900.00, 'emissao_at' => '2026-09-20 10:00:00']);

        $membro = $this->membroDe($account, 'operador');
        $ids = fn (string $query): array => collect(
            $this->actingAs($membro, 'sanctum')
                ->getJson('/api/fiscal/documents?'.$query)
                ->assertOk()
                ->json('data')
        )->pluck('id')->sort()->values()->all();

        $this->assertSame([$medio->getKey()], $ids('amount_min=20&amount_max=100'));

        // Cada ponta sozinha é um filtro legítimo: quem só quer até um valor
        // não precisa saber o piso, e quem só quer acima dele não precisa
        // saber o teto.
        $this->assertSame([$barato->getKey(), $medio->getKey()], $ids('amount_max=100'));
        $this->assertSame([$caro->getKey()], $ids('amount_min=100'));
    }

    public function test_lista_recusa_filtro_invalido(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente Da Conta Própria');
        $this->documento($cliente);

        $outra = Account::factory()->create();
        $alheio = $this->cliente($outra, 'Empresa Alienada Com Documento');
        $this->documento($alheio);

        $membro = $this->membroDe($account, 'operador');

        // Filtro inválido é 422 com a chave que está errada, e nunca uma lista
        // vazia: quem recebe vazio acredita que a consulta rodou e não achou.
        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?model[]=invalido')
            ->assertStatus(422)->assertJsonValidationErrors('model.0');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?model[]=nfe&model[]=invalido')
            ->assertStatus(422)->assertJsonValidationErrors('model.1');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?kind=invalido')
            ->assertStatus(422)->assertJsonValidationErrors('kind');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?sort=chave_acesso')
            ->assertStatus(422)->assertJsonValidationErrors('sort');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?direction=do_lado')
            ->assertStatus(422)->assertJsonValidationErrors('direction');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?per_page=10')
            ->assertStatus(422)->assertJsonValidationErrors('per_page');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?amount_min=-1')
            ->assertStatus(422)->assertJsonValidationErrors('amount_min');

        // Faixa invertida nas duas pontas: o fim antes do começo não tem
        // leitura nenhuma, e a data invertida é o mesmo erro.
        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?amount_min=100&amount_max=10')
            ->assertStatus(422)->assertJsonValidationErrors('amount_max');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?issued_from=2026-09-30&issued_to=2026-09-01')
            ->assertStatus(422)->assertJsonValidationErrors('issued_to');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?issued_from=01/09/2026')
            ->assertStatus(422)->assertJsonValidationErrors('issued_from');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?issuer=abc')
            ->assertStatus(422)->assertJsonValidationErrors('issuer');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?page=0')
            ->assertStatus(422)->assertJsonValidationErrors('page');

        // Cliente de outra conta não é um filtro: é um id que não existe para
        // quem pergunta.
        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?client_id='.$alheio->getKey())
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id')
            ->assertJsonMissingPath('data')
            ->assertDontSee('Alienada', false);
    }

    public function test_lista_sem_correspondencia_devolve_total_zero_e_mantem_o_modelo_selecionado(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Documento');
        $this->documento($cliente, ['model' => FiscalModel::Nfe, 'valor_total' => 50.00]);

        $membro = $this->membroDe($account, 'operador');

        // Combinação sem resultado é vazio com total zero, não erro: a tela
        // escreve "nenhum documento corresponde aos filtros".
        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?amount_min=900')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('available_models', []);

        // O modelo selecionado continua na lista mesmo sem linha atrás dele: é
        // o que permite tirar o filtro que esvaziou a tabela.
        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?model[]=nfe&amount_min=900')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('available_models', ['nfe']);
    }

    public function test_lista_nao_expoe_documento_de_outra_conta(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Da Conta Própria');
        $nfe = $this->documento($cliente, ['model' => FiscalModel::Nfe, 'emissao_at' => '2026-09-10 10:00:00']);
        $cte = $this->documento($cliente, ['model' => FiscalModel::Cte, 'emissao_at' => '2026-09-20 10:00:00']);

        $outra = Account::factory()->create();
        $alheio = $this->cliente($outra, 'Empresa Alienada Com Documento');
        $documentoAlheio = $this->documento($alheio, ['model' => FiscalModel::Nfse, 'emissao_at' => '2026-09-28 10:00:00']);

        $response = $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/documents');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $cte->getKey())
            ->assertJsonPath('data.1.id', $nfe->getKey())
            // A lista de modelos também é da conta: um modelo que só a outra
            // conta capturou não é opção aqui.
            ->assertJsonPath('available_models', ['cte', 'nfe']);

        $response->assertDontSee('Alienada', false)
            ->assertDontSee('nfse', false)
            ->assertDontSee((string) $documentoAlheio->chave_acesso, false);
    }

    public function test_lista_aceita_cliente_removido_da_conta_e_recusa_cliente_alheio(): void
    {
        $account = Account::factory()->create();
        $removido = $this->cliente($account, 'Cliente 1 Removido');
        $documento = $this->documento($removido);
        $removido->delete();

        $outra = Account::factory()->create();
        $alheio = $this->cliente($outra, 'Empresa Alienada Com Documento');
        $this->documento($alheio);

        $membro = $this->membroDe($account, 'operador');

        // Cliente removido é histórico legítimo: o documento guardado tem de
        // continuar alcançável pelo seu filtro, e a validação tem de aceitar o
        // id dele — senão a linha existe e ninguém consegue chegar nela.
        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/documents?client_id='.$removido->getKey())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $documento->getKey())
            ->assertJsonPath('data.0.client.name', 'Cliente 1 Removido');

        // Cliente de outra conta, removido ou não, continua fora: a validação
        // é por conta e por id, e a lista também.
        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/documents?client_id='.$alheio->getKey())
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id')
            ->assertJsonMissingPath('data')
            ->assertDontSee('Alienada', false);
    }

    public function test_lista_devolve_apenas_as_chaves_declaradas_sem_caminho_do_xml(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->clienteComCertificado($account, 'Cliente 1 Com Documento');
        $documento = FiscalDocument::factory()->withStoredXml()->create([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'stage' => FiscalStage::Document,
            'digval_confere' => true,
            'mascarado' => true,
        ]);

        // Uma etapa única não tem com quem conferir o digest: `null` é o
        // terceiro estado, e a linha precisa dizer isso em vez de mentir que
        // o digest não bateu.
        $semPar = $this->documento($cliente, [
            'model' => FiscalModel::Cte,
            'emissao_at' => '2026-09-09 10:00:00',
            'digval_confere' => null,
        ]);

        $certificado = ClientCertificate::query()->where('client_id', $cliente->getKey())->sole();

        $response = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links', 'available_models']);

        $linha = $response->json('data.0');

        $this->assertSame([
            'id', 'client', 'model', 'kind', 'stage', 'chave_acesso', 'emitente_cnpj',
            'destinatario_cnpj', 'valor_total', 'emissao_at', 'event_count', 'mascarado', 'digval_confere',
        ], array_keys($linha));
        $this->assertSame(['id', 'name', 'tax_id'], array_keys($linha['client']));

        // As duas linhas são buscadas por id, não por posição: a ordem da
        // página é assunto do teste de ordenação, e uma posição fixa aqui
        // transformaria este teste em mais um teste de ordem.
        $porId = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($porId[$documento->getKey()]['digval_confere']);
        $this->assertTrue($porId[$documento->getKey()]['mascarado']);
        $this->assertNull($porId[$semPar->getKey()]['digval_confere']);

        // O caminho interno do XML é o segredo desta tabela. As needles são os
        // valores reais das colunas, porque `assertDontSee` com string vazia
        // passaria sem ver nada.
        $this->assertNotSame('', (string) $documento->storage_path);
        $response->assertDontSee('storage_path', false)
            ->assertDontSee((string) $documento->storage_path, false)
            ->assertDontSee('sha256', false)
            ->assertDontSee('"digval"', false)
            ->assertDontSee('xml_bytes', false)
            ->assertDontSee('password_encrypted', false)
            ->assertDontSee((string) $certificado->storage_path, false);
    }

    public function test_lista_pagina(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Documentos');

        for ($dia = 0; $dia < 26; $dia++) {
            $this->documento($cliente, ['emissao_at' => now()->subDays($dia)->toDateTimeString()]);
        }

        $membro = $this->membroDe($account, 'operador');

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->actingAs($membro, 'sanctum')->getJson('/api/fiscal/documents?per_page=50')
            ->assertOk()
            ->assertJsonCount(26, 'data')
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_cliente_removido_com_documento_nao_entra_na_cobertura(): void
    {
        $account = Account::factory()->create();
        $this->clienteComCertificado($account, 'Cliente 1 Ativo');

        // A lista mostra histórico de cliente removido e a cobertura conta quem
        // ainda é capturado. São dois eixos: dar `withTrashed()` na relação do
        // documento não pode arrastar o cliente removido para a contagem.
        $removido = $this->clienteComCertificado($account, 'Cliente 2 Removido Com Documento');
        $this->documento($removido);
        $removido->delete();

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 1)
            ->assertJsonPath('data.coverage.capturable', 1)
            ->assertJsonPath('data.coverage.not_capturable', 0)
            ->assertJsonPath('data.attention', [])
            ->assertDontSee('Removido', false);
    }

    public function test_lista_escopa_a_conta_que_recebe_e_nao_a_conta_corrente(): void
    {
        $primeira = Account::factory()->create();
        $segunda = Account::factory()->create();

        $documentoDaPrimeira = $this->documento(
            $this->cliente($primeira, 'Cliente Da Primeira Conta'),
            ['model' => FiscalModel::Nfe]
        );
        $this->documento($this->cliente($segunda, 'Cliente Da Segunda Conta'), ['model' => FiscalModel::Nfse]);

        // O escopo global de `BelongsToAccount` é condicional: ele filtra pela
        // conta corrente e, sem ela, não filtra nada. Na rota da API o
        // middleware `tenant` sempre a define, o que faz o escopo global
        // mascarar um `where('account_id')` faltando no serviço — e é
        // exatamente por isso que o serviço repete a conta por parâmetro. Este
        // teste é o que prova que a repetição existe: ele chama o serviço
        // diretamente, com a conta corrente **ausente**, que é a condição do
        // console e da fila. Sem o `where` explícito, as duas contas entram.
        resolve(CurrentTenant::class)->accountId = null;

        $page = (new FiscalDocuments)->page(
            (int) $primeira->getKey(),
            ['sort' => 'emissao_at', 'direction' => 'desc']
        );

        $this->assertSame([$documentoDaPrimeira->getKey()], $page['rows']->pluck('id')->all());
        $this->assertSame(['nfe'], $page['available_models']);
    }

    private function cliente(Account $account, string $name): Client
    {
        return Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => $name,
        ]);
    }

    private function clienteComCertificado(Account $account, string $name): Client
    {
        $cliente = $this->cliente($account, $name);
        $this->fabricaDeCertificado($cliente)->withPassword()->create();

        return $cliente;
    }

    /**
     * Certificado corrente válido, ainda sem gravar: os estados da factory
     * (`withPassword`, `withoutPassword`) são aplicados em cima dele.
     */
    private function fabricaDeCertificado(Client $cliente): ClientCertificateFactory
    {
        return ClientCertificate::factory()->state([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
            'valid_until' => now()->addMonths(6),
        ]);
    }

    private function cursor(Client $cliente, array $attributes = []): FiscalCursor
    {
        return FiscalCursor::factory()->create(array_merge([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
        ], $attributes));
    }

    private function documento(Client $cliente, array $attributes = []): FiscalDocument
    {
        return FiscalDocument::factory()->create(array_merge([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
        ], $attributes));
    }

    /**
     * Evento de uma chave de acesso já captada, com a posição (`nsu`) que o
     * fisco deu para ele: a terceira entrega da distribuição, que é o mesmo
     * documento e uma linha diferente.
     */
    private function evento(Client $cliente, string $chave, string $eventId, int $nsu, array $attributes = []): FiscalDocument
    {
        return FiscalDocument::factory()->event($eventId)->create(array_merge([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
            'chave_acesso' => $chave,
            'nsu' => $nsu,
        ], $attributes));
    }

    private function membroDe(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    /**
     * Resumo da conta própria, para conferir a lista de atenção inteira sem
     * repetir a chamada HTTP dentro de uma asserção.
     *
     * @return array<string, mixed>
     */
    private function resumo(Account $account): array
    {
        return $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/summary')
            ->assertOk()
            ->json('data');
    }
}
