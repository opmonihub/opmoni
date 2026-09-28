<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproFailure;
use App\Enums\SerproTermProof;
use App\Jobs\IssueSerproTermJob;
use App\Jobs\RenewSerproTermsJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproConnection;
use App\Models\SerproTermProofRecord;
use App\Models\User;
use App\Services\SerproException;
use App\Services\SerproTermManager;
use App\Services\SerproTermSigner;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * O termo de autorização do escritório, emitido, renovado e lido.
 *
 * **O que estes testes provam e o que eles não provam.** Provam o que é do
 * código: que o documento assinado vai para o `/Apoiar` cifrado no banco e
 * cifrado de volta no fio, que a renovação reenvia **os mesmos bytes** sem
 * chamar o assinador, que o estado gravado é o que a resposta do provedor
 * diz, e que o gate recusa a emissão enquanto a prova de contrato não
 * cobre o formato atual. Não provam — e nenhum teste local poderia — que o
 * provedor aceita o documento. É por isso que o gate existe, e é por isso
 * que os casos que abrem o gate gravam a prova de uma vez, para poderem
 * exercitar a emissão: sem isso, o resto do arquivo não teria como rodar.
 *
 * **A forma do `304` é do provedor, e é documentada — e observada, não.** O
 * cabeçalho `etag`, o `cache-control: termo_autorizacao`, o `expires` e o
 * corpo vazio estão descritos em
 * `https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/solucoes/integra-contador-gerenciador/autenticaprocurador/cache/`
 * (lida em 2026-09-28) e são reproduzidos aqui a partir da documentação.
 * **Documentação é o que o provedor afirma que devolve, não o que alguém
 * observou ele devolver**: nenhuma resposta real entrou nesta suíte, o
 * ambiente de demonstração não foi acionado, e `Change task 4.6a` continua
 * por pagar. O que o caso prova é que o nosso leitor aceita a forma
 * documentada e que a forma documentada produz o efeito que o design exige —
 * novo token, mesmo documento.
 */
class SerproAuthorizationTermTest extends TestCase
{
    use RefreshDatabase;

    /** O CNPJ do escritório, que é o que o e-CNPJ dele carrega. */
    private const ESCRITORIO = '33683111000107';

    /** O CNPJ da plataforma, o mesmo que a credencial de `serpro_connections` guarda. */
    private const PLATAFORMA = '12345678000195';

    private const SENHA = 'senha-de-teste';

    private const ROTA = '/api/serpro/authorization-terms';

    /** O `idServico` do envio do termo, e o `path` que o catálogo mapeia. */
    private const SERVICO = 'ENVIOXMLASSINADO81';

    private const SISTEMA = 'AUTENTICAPROCURADOR';

    /**
     * O token do `304` que o brief fixa, e o mesmo token na forma que o
     * provedor documenta — entre aspas e com o prefixo do nome do campo.
     *
     * Os dois precisam funcionar, e a razão está no caso que os usa: a
     * documentação do provedor mostra `etag: "autenticar_procurador_token:<uuid>"`
     * e o brief pede a validação de um UUID cru. Um leitor que só aceitasse
     * uma das duas formas quebraria a renovação diária no ambiente real.
     */
    private const TOKEN_ETAG = '8f68d948-1059-4f42-9aa6-2931670b0a80';

    /** A mesma coisa como o provedor escreve, com aspas e com o prefixo do campo. */
    private const TOKEN_ETAG_DOCUMENTADO = '"autenticar_procurador_token:8f68d948-1059-4f42-9aa6-2931670b0a80"';

    /** O token que a resposta de sucesso traz em `dados`. */
    private const TOKEN = 'b06feea3-1ca8-49f4-bdb4-211ab006cb92';

    /** O `expires` do `304`, no formato de data HTTP que a RFC 7231 define. */
    private const EXPIRES_HTTP = 'Sat, 15 Oct 2022 00:00:01 GMT';

    private static ?string $pfx = null;

    /**
     * O que o próximo POST em `/Apoiar` devolve.
     *
     * Uma resposta só, lida no momento da chamada, e **não** um segundo
     * `Http::fake()`. Registrar o dublê de novo acrescentaria um stub ao
     * registro existente, e o `Http` do Laravel usa o **primeiro** que casa —
     * de modo que a resposta de aceitação da emissão continuaria valendo na
     * renovação, e o `304` do caso de renovação nunca seria exercitado. A
     * leitura por propriedade é o que garante que a segunda chamada do mesmo
     * caso é a que muda.
     *
     * @var array{status: int, body: mixed, headers: array<string, string>}
     */
    private array $apoiar = ['status' => 200, 'body' => '', 'headers' => []];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$pfx = self::pkcs12();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        Http::fake([
            '*/Apoiar' => fn () => Http::response(
                $this->apoiar['body'],
                $this->apoiar['status'],
                $this->apoiar['headers'],
            ),

            // A autenticação é dublê também, e não por belts e suspenders: um
            // caso que avança o relógio além da validade do par em cache
            // chamaria a URL de token de verdade, e o `preventStrayRequests`
            // transformaria uma arredondação de teste num erro de rede.
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        $this->plataforma();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ emissão

    public function test_a_emissao_manda_o_termo_ao_apoiar_com_o_escritorio_como_autor_pedido_de_dados(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();

        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $termo = resolve(SerproTermManager::class)->issue($conta->getKey());

        $this->assertSame(SerproAuthorizationTermState::Autenticado, $termo->state);
        $this->assertSame(self::TOKEN, $termo->token());
        $this->assertSame($conta->getKey(), $termo->account_id);
        $this->assertSame($certificado->document, $termo->author_document);

        $gravado = (string) $termo->getRawOriginal('document_encrypted');

        Http::assertSent(function (Request $request) use ($gravado): bool {
            if (! str_contains($request->url(), '/Apoiar')) {
                return false;
            }

            $corpo = $request->data();

            $this->assertSame(self::SISTEMA, $corpo['pedidoDados']['idSistema']);
            $this->assertSame(self::SERVICO, $corpo['pedidoDados']['idServico']);
            // A versão é a que o provedor publica para este serviço — `1.0` —, e
            // não a do outro serviço que mora no mesmo `path` do catálogo.
            $this->assertSame('1.0', $corpo['pedidoDados']['versaoSistema']);

            $this->assertSame(self::PLATAFORMA, $corpo['contratante']['numero']);
            $this->assertSame(self::ESCRITORIO, $corpo['autorPedidoDados']['numero']);
            $this->assertSame(self::ESCRITORIO, $corpo['contribuinte']['numero']);

            $dados = json_decode($corpo['pedidoDados']['dados'], true);
            $this->assertIsArray($dados);
            $this->assertArrayHasKey('xml', $dados);

            // O provedor pede o XML assinado **em base64**, e o que ele
            // carrega tem de ser o documento assinado — a assinatura
            // verificável, não o template.
            $decodificado = base64_decode($dados['xml'], true);
            $this->assertIsString($decodificado);
            $this->assertStringContainsString('<Signature', $decodificado);
            $this->assertStringContainsString('xmldsig', $decodificado);
            $this->assertStringContainsString(self::ESCRITORIO, $decodificado);

            // E o que foi gravado não é o documento: é o cifrado dele, que
            // abre de volta com o mesmo texto que foi para o fio.
            $this->assertStringNotContainsString('<Signature', $gravado);
            $this->assertSame($decodificado, Crypt::decryptString($gravado));

            return true;
        });
    }

    public function test_a_emissao_grava_o_vencimento_do_documento_e_a_validade_do_token(): void
    {
        Carbon::setTestNow('2026-03-10 09:30:00');

        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $termo = resolve(SerproTermManager::class)->issue($conta->getKey());

        // A vigência do documento é a **data** que o próprio termo assinado
        // traz, e não uma segunda leitura do relógio: a constante do período
        // aplicada de novo ao agora daria a mesma resposta hoje e a outra
        // amanhã, na fronteira do dia.
        $this->assertSame('2026-04-09', $termo->document_expires_on?->format('Y-m-d'));
        $this->assertSame('2026-03-10', $termo->signed_at?->format('Y-m-d'));

        // A validade que o provedor escreve é `2026-03-11T00:00:01` **em São
        // Paulo**, e a linha guarda o instante: `03:00:01` em UTC. Guardar a
        // string local e relê-la como UTC devolveria a meia-noite de Brasília
        // valendo até a meia-noite de Greenwich, e o token viveria três horas
        // depois de o provedor o ter invalidado.
        $this->assertSame('2026-03-11 03:00:01', $termo->token_expires_at?->format('Y-m-d H:i:s'));

        $documento = $this->documentoDe($termo);
        $this->assertSame('20260409', $this->atributo($documento, 'vigencia', 'data'));
    }

    // ----------------------------------------------------------------- renovação

    /**
     * O caso que mais importa do arquivo, e ele é feito de duas metades que
     * uma de cada vez não provariam nada.
     *
     * A primeira é que o **ciphertext do documento fica byte a byte igual**:
     * uma renovação que re-assinasse o termo produziria um documento
     * diferente — outra assinatura, outra data de vigência — e a comparação
     * quebraria. A segunda é que a renovação **responde mesmo sem o e-CNPJ do
     * escritório na base**: o assinante exige o certificado para assinar, e
     * sem ele ele nem chega a produzir bytes. Uma renovação que o chamasse
     * falharia. Juntas, as duas provam que o caminho do `304` não assina.
     *
     * O caso que conferisse só o token novo passaria com o documento
     * re-assinado em silêncio, que é exatamente o defeito que o design do
     * termo diz que não pode existir.
     */
    public function test_a_renovacao_304_troca_o_token_e_deixa_o_documento_intacto_sem_chamar_o_assinador(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        $term = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();

        $before = $term->document_encrypted;
        $vigenciaAntes = $this->atributo($this->documentoDe($term), 'vigencia', 'data');
        $assinaturaAntes = $this->assinaturaDe($term);

        // O e-CNPJ do escritório some da base. O assinante não produz nada
        // sem ele, então uma renovação que o chamasse não voltaria daqui.
        AccountCertificate::query()->where('account_id', $conta->getKey())->delete();

        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG]);

        $renovado = $manager->refresh($conta->getKey());

        $this->assertSame($before, $renovado->document_encrypted);
        $this->assertSame($before, $term->fresh()->document_encrypted);
        $this->assertSame(self::TOKEN_ETAG, $term->fresh()->token());

        // O documento que volta é o mesmo byte a byte, e não só o mesmo
        // ciphertext: é a decifra que se compara, porque o mesmo ciphertext
        // com outra cifra também abriria.
        $this->assertSame($assinaturaAntes, $this->assinaturaDe($renovado));
        $this->assertSame($vigenciaAntes, $this->atributo($this->documentoDe($renovado), 'vigencia', 'data'));

        $this->assertSame(SerproAuthorizationTermState::Autenticado, $renovado->state);

        // E o que foi para o **fio** é exatamente o que está guardado. Sem
        // esta metade, uma renovação que acrescentasse um byte ao documento
        // antes de reenviá-lo passaria: o `document_encrypted` continuaria
        // igual, porque ninguém o reescreveu, e o `304` do provedor deixaria de
        // ser a resposta certa. É a comparação que amarra a linha ao corpo da
        // requisição.
        $enviados = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/Apoiar'));
        $ultimo = $enviados->last();

        $this->assertIsArray($ultimo);

        $dados = json_decode($ultimo[0]->data()['pedidoDados']['dados'], true);

        $this->assertIsArray($dados);
        $this->assertSame(
            $this->xmlDe($renovado),
            base64_decode($dados['xml'], true),
            'A renovação tem de reenviar os bytes guardados, e não uma versão deles.',
        );
    }

    /**
     * A forma do `etag` do provedor é entre aspas e com o nome do campo na
     * frente, e é essa que a renovação real vai encontrar.
     *
     * O caso do brief acima usa o UUID cru; este usa a forma que o provedor
     * publica, e os dois precisam chegar ao mesmo token. Um leitor que só
     * aceitasse a forma crua passaria no caso anterior e reprovaria a
     * renovação de todo dia em produção.
     */
    public function test_a_renovacao_aceita_o_etag_na_forma_documentada_do_provedor(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        $antes = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole()->token();

        $this->respondeApoiar(304, '', [
            'ETag' => self::TOKEN_ETAG_DOCUMENTADO,
            'Cache-Control' => 'termo_autorizacao',
            'Expires' => self::EXPIRES_HTTP,
        ]);

        $renovado = $manager->refresh($conta->getKey());

        $this->assertNotSame($antes, $renovado->token());
        $this->assertSame(self::TOKEN_ETAG, $renovado->token());
        // O `expires` do exemplo do provedor é uma data HTTP em GMT, e é lida
        // como o instante que ela é — o texto do provedor diz "meia-noite de
        // Brasília" e o exemplo diz outra coisa, e a divergência está escrita
        // no manager.
        $this->assertSame('2022-10-15 00:00:01', $renovado->token_expires_at?->format('Y-m-d H:i:s'));
    }

    /**
     * O `304` sem `etag` utilizável é a forma mais cara de erro aqui, porque
     * ela destruiria o token que ainda valia: o `304` diz que nada mudou, e
     * a única coisa que ele traz de novo é o token.
     */
    public function test_um_etag_que_nao_e_uuid_nao_sobrescreve_o_token_que_ainda_valia(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        $antes = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole()->token();

        foreach ([
            'sem o campo' => ['"termo_autorizacao"'],
            'token que não é uuid' => ['"autenticar_procurador_token:token-em-claro"'],
            'vazio' => [''],
        ] as $caso => $etag) {
            $this->respondeApoiar(304, '', ['ETag' => $etag[0]]);

            try {
                $manager->refresh($conta->getKey());
                $this->fail("Um ETag {$caso} deve recusar a renovação.");
            } catch (SerproException $exception) {
                $this->assertSame(SerproFailure::Upstream, $exception->failure);
            }

            $this->assertSame(
                $antes,
                SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole()->token(),
                "O token anterior tem de continuar valendo quando o ETag {$caso} não serve.",
            );
        }
    }

    public function test_o_termo_vencido_e_marcado_vencido_sem_mandar_nada_ao_provedor(): void
    {
        Carbon::setTestNow('2026-03-10 09:30:00');

        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        // A vigência do documento é um **dia do calendário de São Paulo**, e o
        // documento vale por ele inteiro. A meia-noite que encerra o dia 9 é
        // `2026-04-10 03:00` em UTC, e é essa a fronteira — declará-lo vencido
        // à meia-noite de Greenwich faria o escritório assinar de novo três
        // horas antes do prazo, todo dia.
        Carbon::setTestNow('2026-04-10 02:59:59');
        $this->assertSame(
            SerproAuthorizationTermState::Autenticado,
            $manager->refresh($conta->getKey())->state,
            'No dia da vigência o termo ainda vale.',
        );

        Carbon::setTestNow('2026-04-10 03:00:01');
        $antes = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole()->document_encrypted;

        $renovado = $manager->refresh($conta->getKey());

        $this->assertSame(SerproAuthorizationTermState::Vencido, $renovado->state);
        $this->assertSame($antes, $renovado->document_encrypted);
        $this->assertNull($manager->validToken($conta->getKey()), 'Termo vencido não vale token nenhum.');

        // E nada foi enviado: a última chamada foi a do `304` do passo
        // anterior, e o provedor não recebeu nova requisição nenhuma.
        $chamadas = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/Apoiar'));
        $this->assertCount(2, $chamadas);
    }

    public function test_a_recusa_do_provedor_marca_recusado_sem_logar_o_documento_nem_o_token(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();

        $this->fakeApoiarRecusa('AcessoNegado-AUTENTICAPROCURADOR-019');

        Log::spy();

        try {
            resolve(SerproTermManager::class)->issue($conta->getKey());
            $this->fail('Uma recusa do provedor tem de subir como SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertSame('AcessoNegado-AUTENTICAPROCURADOR-019', $exception->providerCode);
        }

        $termo = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();

        $this->assertSame(SerproAuthorizationTermState::Recusado, $termo->state);
        $this->assertNull($termo->token());
        $this->assertNull(resolve(SerproTermManager::class)->validToken($conta->getKey()));

        // O motivo é o **código** do provedor, nunca o texto que ele escreveu
        // sobre a requisição: o texto descreve o XML que acabamos de mandar.
        $this->assertSame('AcessoNegado-AUTENTICAPROCURADOR-019', $termo->state_reason);
        $this->assertStringNotContainsString('Termo recusado', (string) $termo->state_reason);

        // Nada de log. O spying é do `Log` inteiro, e a exceção nem chegou a
        // ser registrada por ninguém: o que a checagem garante é que este
        // caminho não escreve documento, token ou senha em lugar nenhum.
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    public function test_uma_falha_do_provedor_que_nao_e_recusa_guarda_o_token_anterior(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        $antes = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole()->token();

        $this->respondeApoiar(503, ['status' => 503, 'dados' => null, 'mensagens' => []]);

        try {
            $manager->refresh($conta->getKey());
            $this->fail('Uma indisponibilidade do provedor tem de subir como SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Upstream, $exception->failure);
        }

        $depois = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();

        // O provedor não está disponível é evento dele, não veredito sobre o
        // termo: o estado continua o que era e o token continua valendo.
        $this->assertSame(SerproAuthorizationTermState::Autenticado, $depois->state);
        $this->assertSame($antes, $depois->token());
    }

    // --------------------------------------------------------------------- gate

    public function test_a_emissao_e_bloqueada_quando_nenhum_teste_de_contrato_foi_registrado(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $this->assertSame(SerproTermProof::Ausente, SerproConnection::sole()->termProof());

        try {
            resolve(SerproTermManager::class)->issue($conta->getKey());
            $this->fail('Sem prova de contrato a emissão tem de ser recusada.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            // A mensagem diz **qual** das duas metades faltou, porque as duas
            // têm consertos diferentes: uma é gravar a prova, a outra é
            // regravá-la porque o documento mudou depois.
            $this->assertStringContainsString('nenhum teste de contrato', $exception->getMessage());
        }

        $this->assertDatabaseCount('serpro_authorization_terms', 0);
        Http::assertNothingSent();
    }

    public function test_a_emissao_reabre_o_gate_quando_o_digest_gravado_deixa_de_bater_com_o_documento_atual(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        // A prova existe, mas foi gravada para um formato que já não é o do
        // documento de hoje: o digest não bate e o gate fecha sozinho, sem
        // ninguém decidir fechá-lo.
        SerproConnection::sole()->forceFill([
            'term_format_sha256' => hash('sha256', 'formato-que-ja-nao-e-o-do-documento'),
            'term_format_proven_at' => now(),
        ])->save();

        $this->assertSame(SerproTermProof::Divergente, SerproConnection::sole()->termProof());

        try {
            resolve(SerproTermManager::class)->issue($conta->getKey());
            $this->fail('Uma prova divergente tem de fechar a emissão.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertStringContainsString('não corresponde ao formato atual', $exception->getMessage());
        }

        $this->assertDatabaseCount('serpro_authorization_terms', 0);
        Http::assertNothingSent();
    }

    public function test_o_gate_depende_das_duas_colunas_e_nao_de_uma_delas_so(): void
    {
        $conexao = SerproConnection::sole();

        $conexao->forceFill([
            'term_format_sha256' => SerproTermSigner::formatDigest(),
            'term_format_proven_at' => null,
        ])->save();
        $this->assertSame(SerproTermProof::Ausente, $conexao->fresh()->termProof());

        $conexao->forceFill(['term_format_proven_at' => now()])->save();
        $this->assertSame(SerproTermProof::Provado, $conexao->fresh()->termProof());

        $conexao->forceFill(['term_format_sha256' => hash('sha256', 'outro-formato')])->save();
        $this->assertSame(SerproTermProof::Divergente, $conexao->fresh()->termProof());
    }

    public function test_as_duas_colunas_da_prova_nao_sao_preenchiveis_e_nao_sao_ocultas_por_omissao(): void
    {
        $conexao = SerproConnection::sole();

        $this->assertFalse($conexao->isFillable('term_format_sha256'));
        $this->assertFalse($conexao->isFillable('term_format_proven_at'));

        // E o `fill()` realmente não escreve nada: um `fill` com as duas
        // colunas e com as do segredo não altera nenhuma delas.
        $digest = $conexao->getRawOriginal('term_format_sha256');
        $provaEm = $conexao->getRawOriginal('term_format_proven_at');

        $conexao->fill([
            'term_format_sha256' => hash('sha256', 'preenchido-por-uma-requisição'),
            'term_format_proven_at' => now(),
            'consumer_secret_encrypted' => 'segredo-inventado',
        ]);

        $this->assertSame($digest, $conexao->getRawOriginal('term_format_sha256'));
        $this->assertSame($provaEm, $conexao->getRawOriginal('term_format_proven_at'));
        $this->assertNotSame('segredo-inventado', $conexao->getRawOriginal('consumer_secret_encrypted'));
    }

    // ---------------------------------------------------------- o comando da prova

    public function test_a_prova_e_gravada_com_o_digest_que_o_codigo_mede_e_nao_com_um_digitado(): void
    {
        // Sem confirmação: o comando é de operador e este teste roda sem
        // terminal. O `--force` é o que o console não interativo exige.
        $this->artisan('serpro:record-term-proof', [
            'author' => 'Dante de Oliveira',
            '--force' => true,
        ])->assertSuccessful();

        $conexao = SerproConnection::sole();

        $this->assertSame(SerproTermSigner::formatDigest(), $conexao->term_format_sha256);
        $this->assertNotNull($conexao->term_format_proven_at);
        $this->assertSame(SerproTermProof::Provado, $conexao->termProof());

        $registro = SerproTermProofRecord::query()->sole();

        $this->assertSame(SerproTermSigner::formatDigest(), $registro->term_format_sha256);
        $this->assertSame('Dante de Oliveira', $registro->recorded_by);
        $this->assertNull($registro->superseded_sha256);
        $this->assertNull($registro->superseded_at);
    }

    public function test_o_comando_recusa_sobrescrever_uma_prova_que_ja_existe_sem_forcar_e_com_motivo(): void
    {
        $this->provaDeContrato('Dante de Oliveira');

        $provaAntes = SerproConnection::sole()->term_format_proven_at?->format('Y-m-d H:i:s');

        // Primeiro sem `--replace`: uma prova vigente não se substitui por
        // engano, porque o efeito é trocar um digest testado por outro.
        $this->artisan('serpro:record-term-proof', ['author' => 'Outra Pessoa', '--force' => true])
            ->expectsOutputToContain('Já existe')
            ->assertFailed();

        $this->assertSame('Dante de Oliveira', SerproTermProofRecord::query()->sole()->recorded_by);

        // Com `--replace` e **sem** `--reason`: a troca de uma prova é um
        // ato que alguém precisa declarar, não um efeito colateral.
        $this->artisan('serpro:record-term-proof', [
            'author' => 'Outra Pessoa',
            '--replace' => true,
            '--force' => true,
        ])->expectsOutputToContain('motivo')
            ->assertFailed();

        $this->assertSame('Dante de Oliveira', SerproTermProofRecord::query()->sole()->recorded_by);
        $this->assertSame($provaAntes, SerproConnection::sole()->term_format_proven_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_prova_substituida_guarda_o_digest_e_a_hora_que_substituiu(): void
    {
        $this->provaDeContrato('Dante de Oliveira');
        $this->artisan('serpro:record-term-proof', ['author' => 'Dante de Oliveira', '--force' => true]);

        $antes = SerproConnection::sole();
        $digestAntes = $antes->term_format_sha256;
        $provaAntes = $antes->term_format_proven_at;

        // O formato mudou entre as duas gravações — é o que reabre o gate —
        // e a prova nova não pode apagar de qual formato ela veio.
        SerproConnection::sole()->forceFill(['term_format_sha256' => hash('sha256', 'formato-anterior')])->save();

        Carbon::setTestNow('2026-05-04 11:00:00');

        $this->artisan('serpro:record-term-proof', [
            'author' => 'Belmiro Marques',
            '--replace' => true,
            '--reason' => 'Contrato de 2026-05-04 no ambiente de demonstração.',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(SerproTermSigner::formatDigest(), SerproConnection::sole()->term_format_sha256);
        $this->assertSame('2026-05-04 11:00:00', SerproConnection::sole()->term_format_proven_at?->format('Y-m-d H:i:s'));

        $registros = SerproTermProofRecord::query()->orderBy('id')->get();

        $this->assertCount(2, $registros);

        $nova = $registros->last();
        $this->assertSame('Belmiro Marques', $nova->recorded_by);
        $this->assertSame('Contrato de 2026-05-04 no ambiente de demonstração.', $nova->reason);
        $this->assertSame(hash('sha256', 'formato-anterior'), $nova->superseded_sha256);
        $this->assertSame($provaAntes->format('Y-m-d H:i:s'), $nova->superseded_at?->format('Y-m-d H:i:s'));

        // A prova antiga continua na base, intacta: o registro é acrescido, e
        // a linha que ele substituiu não vira lixo.
        $antiga = $registros->first();
        $this->assertSame('Dante de Oliveira', $antiga->recorded_by);
        $this->assertSame($digestAntes, $antiga->term_format_sha256);
    }

    public function test_o_registro_da_prova_so_cresce_e_nao_admite_alteracao_ou_apagamento(): void
    {
        $this->provaDeContrato('Dante de Oliveira');

        $registro = SerproTermProofRecord::query()->sole();

        // Não existe coluna de atualização: um `update` que mudasse um valor
        // deixaria a linha com um único carimbo, dizendo que aquilo foi
        // gravado naquele momento, e seria mentira.
        $this->assertFalse(
            Schema::hasColumn('serpro_term_proof_records', 'updated_at'),
            'Um registro de auditoria não pode ter coluna de atualização.',
        );
        $this->assertFalse(Schema::hasColumn('serpro_term_proof_records', 'deleted_at'));

        try {
            $registro->forceFill(['recorded_by' => 'Falsificado'])->save();
            $this->fail('Um registro de prova não pode ser alterado.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('não alterado', $exception->getMessage());
        }

        try {
            $registro->delete();
            $this->fail('Um registro de prova não pode ser apagado.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('não pode ser apagado', $exception->getMessage());
        }

        $this->assertSame('Dante de Oliveira', SerproTermProofRecord::query()->sole()->recorded_by);
    }

    // ---------------------------------------------------------------- renovação diária

    public function test_a_renovacao_diario_vai_por_conta_explicita_e_nao_pelo_tenant_que_sobrou(): void
    {
        [$alvo] = $this->escritorio('Escritório Alvo');
        [$outro] = $this->escritorio('Escritório de Outro');

        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($alvo->getKey());
        $manager->issue($outro->getKey());

        // O `queue:work` é um processo longo e o `CurrentTenant` é um
        // singleton que ninguém reseta: o valor da execução anterior
        // sobrevive. O job tem de levar a conta e recarregá-la.
        resolve(CurrentTenant::class)->accountId = $outro->getKey();

        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG]);

        (new RenewSerproTermsJob($alvo->getKey()))->handle($manager);

        $this->assertSame($alvo->getKey(), resolve(CurrentTenant::class)->accountId);

        // A leitura é pela conta do job, e a outra conta fica com o token da
        // emissão: um `304` que tivesse alcançado a linha errada trocaria o
        // token de um escritório pelo de outro, e as duas linhas continuariam
        // `autenticado`. A segunda leitura sai do escopo de conta de propósito
        // — ela é sobre a conta **vizinha**, e é o escopo global de
        // `BelongsToAccount` que esconderia a linha de quem a有关系.
        $this->assertSame(
            self::TOKEN_ETAG,
            SerproAuthorizationTerm::query()->where('account_id', $alvo->getKey())->sole()->token(),
        );
        $this->assertSame(
            self::TOKEN,
            SerproAuthorizationTerm::query()->withoutGlobalScopes()->where('account_id', $outro->getKey())->sole()->token(),
        );
    }

    public function test_o_comando_de_renovacao_despacha_um_job_por_conta_que_ja_tem_termo(): void
    {
        [$alvo] = $this->escritorio('Escritório Alvo');
        $this->escritorio('Escritório Sem Termo');

        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        resolve(SerproTermManager::class)->issue($alvo->getKey());

        Queue::fake();

        $this->artisan('serpro:renew-terms')->assertSuccessful();

        // Só a conta que tem termo: a emissão é do upload do e-CNPJ, e
        // renovar uma conta sem termo seria pedir ao escritório algo que ele
        // ainda não entregou.
        Queue::assertPushed(
            RenewSerproTermsJob::class,
            fn (RenewSerproTermsJob $job): bool => $job->accountId === $alvo->getKey(),
        );
        Queue::assertPushed(RenewSerproTermsJob::class, 1);
    }

    public function test_o_upload_do_ecnpj_agenda_a_emissao_depois_do_commit(): void
    {
        Queue::fake();

        $conta = Account::factory()->create();

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post('/api/serpro/account-certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('escritorio.p12', (string) self::$pfx),
                'password' => self::SENHA,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        Queue::assertPushed(IssueSerproTermJob::class, fn (IssueSerproTermJob $job): bool => $job->accountId === $conta->getKey());
        Queue::assertPushed(IssueSerproTermJob::class, 1);
    }

    public function test_a_falha_da_emissao_registra_estado_sem_expor_material(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();

        $this->fakeApoiarRecusa('Erro-AUTENTICAPROCURADOR-001', 400, 'xml ausente.');

        Log::spy();

        try {
            resolve(SerproTermManager::class)->issue($conta->getKey());
            $this->fail('A recusa do provedor tem de subir como SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
        }

        $termo = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();

        $this->assertSame(SerproAuthorizationTermState::Recusado, $termo->state);
        $this->assertNotNull($termo->document_encrypted);
        $this->assertNull($termo->token());

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    /**
     * O gate fechado **não** pode virar `500` no upload do e-CNPJ.
     *
     * Com `QUEUE_CONNECTION=sync` — que é o da suíte e pode ser o de uma
     * instalação — o job de emissão roda dentro da transação do upload, e uma
     * exceção atravessada transformaria o `200` do certificado em erro. Como o
     * gate está fechado e estar fechado é o estado correto de quem ainda não
     * tem teste de contrato, **toda** conta que subisse um e-CNPJ receberia um
     * erro por causa de uma prova que o produto ainda não tem.
     *
     * O caso verifica os três estados que a pessoa ve: o `200` do upload, o
     * certificado gravado e o termo **ausente** — que é a leitura que diz ao
     * escritório que ele precisa entregar o e-CNPJ, e não uma tela de erro.
     */
    public function test_o_upload_da_200_mesmo_com_o_gate_da_emissao_fechado(): void
    {
        $conta = Account::factory()->create();

        $this->assertSame(SerproTermProof::Ausente, SerproConnection::sole()->termProof());

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post('/api/serpro/account-certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('escritorio.p12', (string) self::$pfx),
                'password' => self::SENHA,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.document', self::ESCRITORIO);

        $this->assertNotNull(AccountCertificate::currentFor($conta->getKey()));
        $this->assertNull(SerproAuthorizationTerm::query()->withoutGlobalScopes()->where('account_id', $conta->getKey())->first());

        // E a leitura do termo diz `ausente`, que é a informação que manda a
        // tela agir — e não um estado de erro que ela não sabe Translate.
        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->getJson(self::ROTA)
            ->assertOk()
            ->assertJsonPath('data.state', SerproAuthorizationTermState::Ausente->value);
    }

    /**
     * O job engole a falha, e o que ele deixa é o estado — nunca material.
     *
     * A asserção de sigilo aqui é a última deste arquivo e a mais ampla: o
     * `Log` inteiro é um espião, e o único registro que o caminho da emissão
     * pode fazer é o do próprio job, com o rótulo da falha, o id da conta e a
     * frase da `SerproException` — que não carrega documento, token nem senha.
     */
    public function test_o_job_de_emissao_registra_a_falha_sem_material_e_sem_derrubar_o_upload(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarRecusa('AcessoNegado-AUTENTICAPROCURADOR-019');

        Log::spy();

        (new IssueSerproTermJob($conta->getKey()))->handle(resolve(SerproTermManager::class));

        $registrado = '';

        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $mensagem, array $contexto) use (&$registrado): bool {
                $registrado = $mensagem.json_encode($contexto, JSON_UNESCAPED_UNICODE);

                return true;
            },
        );

        $this->assertStringContainsString('não pôde ser concluída', $registrado);
        $this->assertStringNotContainsString('<Signature', $registrado);
        $this->assertStringNotContainsString('xmldsig', $registrado);
        $this->assertStringNotContainsString(self::TOKEN, $registrado);
        $this->assertStringNotContainsString(self::SENHA, $registrado);
        $this->assertStringNotContainsString(self::ESCRITORIO, $registrado);

        // E o estado continua sendo o do provedor, não o do job.
        $this->assertSame(
            SerproAuthorizationTermState::Recusado,
            SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole()->state,
        );
    }

    // ------------------------------------------------------------------- leitura

    public function test_a_leitura_devolve_o_contrato_do_termo_e_nada_de_material(): void
    {
        Carbon::setTestNow('2026-03-10 09:30:00');

        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $termo = resolve(SerproTermManager::class)->issue($conta->getKey());

        $resposta = $this->actingAs($this->membroDe($conta, 'user'), 'sanctum')
            ->getJson(self::ROTA)
            ->assertOk();

        $this->assertSame([
            'document_present',
            'expires_on',
            'signed_at',
            'state',
        ], $this->chavesOrdenadas($resposta->json('data')));

        $this->assertSame(SerproAuthorizationTermState::Autenticado->value, $resposta->json('data.state'));
        $this->assertSame('2026-04-09', $resposta->json('data.expires_on'));
        $this->assertTrue($resposta->json('data.document_present'));

        $corpo = $resposta->getContent();

        // A lista acima já fecha o contrato, e estas três afirmações fecham o
        // que a lista não pega: um documento inteiro, o token e o texto
        // cifrado nunca estão ali, qualquer que seja a forma da resposta.
        $this->assertStringNotContainsString('<Signature', $corpo);
        $this->assertStringNotContainsString(self::TOKEN, $corpo);
        $this->assertStringNotContainsString((string) $termo->getRawOriginal('document_encrypted'), $corpo);

        $resposta->assertJsonMissingPath('data.document')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.author_document');
    }

    public function test_a_leitura_de_uma_conta_que_nao_tem_termo_responde_que_ele_esta_ausente(): void
    {
        $conta = Account::factory()->create();

        // Não é `404`: a ausência de termo é um estado do produto, e a tela
        // precisa dele para pedir o certificado — um `404` seria indistinguível
        // de rota errada.
        $this->actingAs($this->membroDe($conta, 'operador'), 'sanctum')
            ->getJson(self::ROTA)
            ->assertOk()
            ->assertJsonPath('data.state', SerproAuthorizationTermState::Ausente->value)
            ->assertJsonPath('data.document_present', false)
            ->assertJsonPath('data.expires_on', null)
            ->assertJsonPath('data.signed_at', null);
    }

    public function test_o_termo_de_uma_conta_nao_existe_para_o_membro_de_outra(): void
    {
        [$dona] = $this->escritorio('Escritório Dona');
        [$vizinha] = $this->escritorio('Escritório Vizinho');

        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        resolve(SerproTermManager::class)->issue($dona->getKey());

        $this->actingAs($this->membroDe($vizinha, 'admin'), 'sanctum')
            ->getJson(self::ROTA)
            ->assertOk()
            ->assertJsonPath('data.state', SerproAuthorizationTermState::Ausente->value)
            ->assertJsonPath('data.document_present', false);
    }

    public function test_a_leitura_exige_sessao(): void
    {
        $this->getJson(self::ROTA)->assertUnauthorized();
    }

    // ------------------------------------------------------------- o token válido

    public function test_o_token_valido_so_sobe_com_o_termo_no_prazo_e_sem_token_vencido(): void
    {
        Carbon::setTestNow('2026-03-10 09:30:00');

        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);

        $this->assertNull($manager->validToken($conta->getKey()), 'Conta sem termo não tem token.');

        $manager->issue($conta->getKey());
        $this->assertSame(self::TOKEN, $manager->validToken($conta->getKey()));

        // O provedor promete o token "até a meia-noite do dia seguinte, horário
        // de Brasília", e `data_hora_expiracao` é `2026-03-11T00:00:01` nesse
        // fuso. A fronteira que importa é a da meia-noite **de São Paulo**,
        // três horas depois da de Greenwich, e é a linha que o plano 04 vai
        // ler para recusar uma sincronização.
        Carbon::setTestNow('2026-03-11 02:59:59');
        $this->assertSame(self::TOKEN, $manager->validToken($conta->getKey()));

        Carbon::setTestNow('2026-03-11 03:00:02');
        $this->assertNull(
            $manager->validToken($conta->getKey()),
            'Passado o vencimento do token, o plano 04 tem de ver a recusa e não um token morto.',
        );
    }

    public function test_o_token_que_a_linha_nao_guarda_nao_sobe(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        $termo = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();
        $termo->forceFill(['token_encrypted' => null])->save();

        $this->assertNull($manager->validToken($conta->getKey()));
    }

    // -------------------------------------------------------------------- esquema

    public function test_as_colunas_das_duas_migracoes_existem_com_o_que_o_uso_delas_pressupoe(): void
    {
        $colunas = Schema::getColumnListing('serpro_authorization_terms');

        foreach ([
            'account_id',
            'author_document',
            'document_encrypted',
            'document_expires_on',
            'token_encrypted',
            'token_expires_at',
            'state',
            'state_reason',
            'signed_at',
            'last_submitted_at',
        ] as $coluna) {
            $this->assertContains($coluna, $colunas, "A tabela do termo tem de ter a coluna {$coluna}.");
        }

        // Um termo por conta é do **banco**, e não da aplicação: duas linhas
        // para a mesma conta fariam a renovação diária reenviar o mesmo
        // documento duas vezes, com dois tokens diferentes em jogo, e nada
        // no produto teria como dizer qual dos dois é o bom.
        [$conta] = $this->escritorio('Escritório de Teste');

        // `forceCreate` e não `create`: o model tem `#[Fillable]` e o
        // documento cifrado não está nele, e é essa a garantia que o
        // `SerproTermManager` atravessa com `forceFill`. Um `create` aqui
        // gravaria uma linha sem documento, que é a forma silenciosa do
        // defeito que o `#[Fillable]` existe para impedir.
        SerproAuthorizationTerm::forceCreate([
            'account_id' => $conta->getKey(),
            'author_document' => self::ESCRITORIO,
            'document_encrypted' => Crypt::encryptString('<termoDeAutorizacao/>'),
            'document_expires_on' => now()->addDays(30)->startOfDay(),
            'state' => SerproAuthorizationTermState::Pendente,
            'signed_at' => now(),
            'last_submitted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        SerproAuthorizationTerm::forceCreate([
            'account_id' => $conta->getKey(),
            'author_document' => self::ESCRITORIO,
            'document_encrypted' => Crypt::encryptString('<termoDeAutorizacao/>'),
            'document_expires_on' => now()->addDays(30)->startOfDay(),
            'state' => SerproAuthorizationTermState::Pendente,
            'signed_at' => now(),
            'last_submitted_at' => now(),
        ]);

        $this->assertSame(1, SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->count());
    }

    public function test_as_duas_colunas_do_gate_estao_na_credencial_de_plataforma(): void
    {
        $conexao = Schema::getColumnListing('serpro_connections');

        $this->assertContains('term_format_sha256', $conexao);
        $this->assertContains('term_format_proven_at', $conexao);
    }

    // ------------------------------------------------------------------ arranjos

    /**
     * A credencial de plataforma, com um e-CNPJ real cujo CNPJ é o mesmo da
     * coluna de contratante: `assertIdentity()` roda antes de qualquer rede e
     * um certificado de mentira seria recusado por um motivo que nada tem a
     * ver com o que estes testes exercitam.
     */
    private function plataforma(): SerproConnection
    {
        return SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($this->pfxDaPlataforma()),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
            'certificate_subject' => 'CN=Plataforma de Teste:'.self::PLATAFORMA,
            'contratante_numero' => self::PLATAFORMA,
            'contratante_tipo' => 2,
            'certificate_valid_until' => now()->addYear(),
        ]);
    }

    private function escritorio(string $nome): array
    {
        $conta = Account::factory()->create(['name' => $nome]);

        $certificado = AccountCertificate::factory()->for($conta)->create([
            'document' => self::ESCRITORIO,
            'certificate_encrypted' => Crypt::encryptString(base64_encode((string) self::$pfx)),
            'password_encrypted' => Crypt::encryptString(self::SENHA),
        ]);

        return [$conta, $certificado];
    }

    /**
     * A prova que abre o gate, gravada pelo mesmo caminho que o comando usa.
     *
     * O digest é o de `SerproTermSigner::formatDigest()` e o instante é o de
     * agora — nenhum dos dois é escrito à mão aqui, porque um teste que
     * digitasses o digest estaria testando o valor que ele mesmo escreveu.
     */
    private function provaDeContrato(string $quem = 'Operador de Teste'): SerproTermProofRecord
    {
        $digest = SerproTermSigner::formatDigest();

        SerproConnection::sole()->forceFill([
            'term_format_sha256' => $digest,
            'term_format_proven_at' => now(),
        ])->save();

        return SerproTermProofRecord::query()->create([
            'term_format_sha256' => $digest,
            'recorded_by' => $quem,
        ]);
    }

    private function membroDe(Account $conta, string $papel = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $conta->getKey(), 'user_id' => $user->getKey(), 'role' => $papel]);
        $user->forceFill(['current_account_id' => $conta->getKey()])->save();

        return $user->refresh();
    }

    private function fakeTokenAutenticado(): void
    {
        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 600);
    }

    /**
     * O `/Apoiar` passa a responder assim a partir de agora, e o dublê
     * instalado no `setUp` é quem lê isto na hora da chamada.
     *
     * @param  array<string, string>  $cabecalhos
     */
    private function respondeApoiar(int $status, mixed $body = '', array $cabecalhos = []): void
    {
        $this->apoiar = ['status' => $status, 'body' => $body, 'headers' => $cabecalhos];
    }

    /**
     * A resposta de sucesso, com o corpo no formato que o provedor publica:
     * `dados` como texto escapado, e dentro dele o token e a validade.
     */
    private function fakeApoiarAceito(): void
    {
        $this->respondeApoiar(200, [
            'status' => 200,
            'dados' => json_encode([
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => '2026-03-11T00:00:01',
            ], JSON_THROW_ON_ERROR),
            'mensagens' => [['codigo' => '200', 'texto' => 'Sucesso na execução.']],
        ]);
    }

    /**
     * A recusa do provedor, com o envelope que ele manda e o texto que
     * descreve o documento — o texto é o que não pode subir para a linha nem
     * para a exceção, e o caso da recusa é o que prova isso.
     */
    private function fakeApoiarRecusa(string $codigo, int $status = 403, string $texto = 'Termo recusado para o xml assinado enviado nesta requisição.'): void
    {
        $this->respondeApoiar($status, [
            'status' => $status,
            'dados' => null,
            'mensagens' => [['codigo' => $codigo, 'texto' => $texto]],
        ]);
    }

    /**
     * O documento assinado, decifrado, lido de volta.
     *
     * Comparar o XML e não o ciphertext é o que dá força ao caso da renovação:
     * o mesmo texto cifrado abriria com outra chave, e o que precisa ser
     * igual é o **documento** que o provedor recebe.
     */
    private function documentoDe(SerproAuthorizationTerm $termo): DOMDocument
    {
        $documento = new DOMDocument;
        $documento->preserveWhiteSpace = true;

        $this->assertTrue(
            $documento->loadXML($this->xmlDe($termo), LIBXML_NONET),
            'O documento guardado tem de ser XML legível depois de decifrado.',
        );

        return $documento;
    }

    private function xmlDe(SerproAuthorizationTerm $termo): string
    {
        return Crypt::decryptString((string) $termo->getRawOriginal('document_encrypted'));
    }

    private function atributo(DOMDocument $documento, string $elemento, string $atributo): string
    {
        $no = (new DOMXPath($documento))->query(sprintf('/*/dados/%s', $elemento))?->item(0);

        return $no === null ? '' : (string) $no->attributes?->getNamedItem($atributo)?->nodeValue;
    }

    /** O `SignatureValue`: a assinatura em si, e não o documento inteiro. */
    private function assinaturaDe(SerproAuthorizationTerm $termo): string
    {
        $xpath = new DOMXPath($this->documentoDe($termo));
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        return (string) $xpath->evaluate('string(//ds:SignatureValue)');
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function chavesOrdenadas(mixed $valor): array
    {
        $this->assertIsArray($valor);

        $chaves = array_keys($valor);
        sort($chaves);

        return $chaves;
    }

    private function pfxDaPlataforma(): string
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $chave = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($chave);

        $csr = openssl_csr_new(
            ['CN' => 'Plataforma de Teste:'.self::PLATAFORMA],
            $chave,
            array_merge(['digest_alg' => 'sha256'], $config),
        );
        $this->assertNotFalse($csr);

        $certificado = openssl_csr_sign($csr, null, $chave, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificado);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificado, $pfx, $chave, self::SENHA));

        return $pfx;
    }

    private static function pkcs12(): string
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $chave = openssl_pkey_new(array_merge(
            ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));

        $csr = $chave === false
            ? false
            : openssl_csr_new(['CN' => 'Escritorio de Teste:'.self::ESCRITORIO], $chave, array_merge(['digest_alg' => 'sha256'], $config));

        $certificado = $csr === false
            ? false
            : openssl_csr_sign($csr, null, $chave, 1, array_merge(['digest_alg' => 'sha256'], $config));

        $conteudo = '';
        $exportado = $certificado !== false && openssl_pkcs12_export($certificado, $conteudo, $chave, self::SENHA);

        if (! $exportado || $conteudo === '') {
            throw new RuntimeException('Não foi possível gerar o e-CNPJ de teste.');
        }

        return $conteudo;
    }
}
