<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalModel;
use App\Http\Controllers\Tenant\FiscalDocumentController;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Fiscal\Read\FiscalCoverage;
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
