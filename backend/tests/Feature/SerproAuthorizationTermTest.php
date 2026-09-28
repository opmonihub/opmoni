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
use App\Policies\SerproAuthorizationTermPolicy;
use App\Services\SerproException;
use App\Services\SerproTermManager;
use App\Services\SerproTermSigner;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    /** A razão social que a credencial ganha quando o caso a rotaciona. */
    private const OUTRA_PLATAFORMA = 'Outra Empresa de Teste';

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

    /**
     * O `expires` do `304` **tal como o provedor publica**: o exemplo dele é de
     * outubro de 2022, e é esse valor que o caso da validade vencida usa — ele
     * está no passado desde 2022, e é o que prova que a regra trata a data
     * documentada do provedor como o que ela é.
     */
    private const EXPIRES_HTTP = 'Sat, 15 Oct 2022 00:00:01 GMT';

    /**
     * A mesma forma, com a validade no futuro.
     *
     * O formato é o que RFC 7231 define e é o que o `etag` documentado vem
     * acompanhado; só a data anda. Uma validade vencida deixa o termo
     * `validado` — o estado honesto de quem não tem token em uso —, de modo que
     * um caso que afirma `autenticado` precisa de uma validade que ainda valha.
     */
    private const EXPIRES_HTTP_FUTURO = 'Wed, 15 Mar 2034 00:00:01 GMT';

    /**
     * O mesmo token do brief, em maiúsculas, como o provedor pode mandá-lo.
     *
     * Um UUID é escrito por padrão em minúsculas, e o cabeçalho vem de um
     * serviço de terceiros. Normalizar a credencial para minúsculas "por
     * segurança" seria mexer nos bytes de um valor que volta para o provedor
     * como cabeçalho em toda chamada, e o ganho seria zero.
     */
    private const TOKEN_ETAG_MAIUSCULO = '"autenticar_procurador_token:8F68D948-1059-4F42-9AA6-2931670B0A80"';

    /**
     * Uma data que não existe, nos dois formatos que o provedor usa.
     *
     * Mês 13 com dia 45 e hora 99:99:99 é a entrada que faz o PHP **transbordar**
     * os campos em vez de recusá-los: `2026-13-45T99:99:99` vira uma data real e
     * futura, e um token com essa validade seria servido por um ano.
     */
    private const DATA_IMPOSSIVEL = '2026-13-45T99:99:99';

    private const EXPIRES_IMPOSSIVEL = 'quarta-feira, 45 de mes inexistente de 2026';

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

    /** Quantas leituras de `serpro_connections` o caso atual contou. */
    private int $leituras = 0;

    /** Se a contagem de leituras está armada para a medição atual. */
    private bool $contando = false;

    /**
     * A rotação da credencial, armada por um caso e disparada na leitura.
     *
     * Trocar `certificate_subject` logo depois da **primeira** leitura da linha
     * é a rotação de credencial que o argumento da docblock descreve: o gate é
     * decidido por uma linha e o documento assinado por outra. Só o `subject`
     * muda — o número do contratante fica — porque é ele que produz o nome do
     * `destinatario`, e a identidade conferida pelo transporte depende do número
     * e do certificado, não do nome.
     */
    private bool $rotacionando = false;

    /** Se a rotação já aconteceu, para a reentrância do próprio `DB::listen`. */
    private bool $rotacionado = false;

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

        /*
         * A contagem de leituras da credencial é feita por `DB::listen`, que é
         * global: ela só vale para o caso que a arma, e `$this->contando` é o
         * que impede o resto do teste de entrar na contagem — o mesmo arranjo de
         * `SerproAccountCertificateTest`, e pelo mesmo motivo.
         */
        DB::listen(function (QueryExecuted $query): void {
            $leitura = str_contains($query->sql, 'from "serpro_connections"');

            if ($leitura && $this->rotacionando && ! $this->rotacionado) {
                $this->rotacionado = true;
                $this->rotacionarCredencial();
            }

            if ($this->contando && $leitura) {
                $this->leituras++;
            }
        });

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

        $this->respondeApoiar(304, '', [
            'ETag' => self::TOKEN_ETAG,
            'Expires' => self::EXPIRES_HTTP_FUTURO,
        ]);

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
            'Expires' => self::EXPIRES_HTTP_FUTURO,
        ]);

        $renovado = $manager->refresh($conta->getKey());

        $this->assertNotSame($antes, $renovado->token());
        $this->assertSame(self::TOKEN_ETAG, $renovado->token());
        $this->assertSame(SerproAuthorizationTermState::Autenticado, $renovado->state);
        // O `expires` é uma data HTTP em GMT, e é lida como o instante que ela
        // é — o texto do provedor diz "meia-noite de Brasília" e o exemplo
        // dele diz outra coisa, e a divergência está escrita no manager.
        $this->assertSame('2034-03-15 00:00:01', $renovado->token_expires_at?->format('Y-m-d H:i:s'));
    }

    /**
     * O `304` sem `etag` utilizável é a forma mais cara de erro aqui, porque
     * ela destruiria o token que ainda valia: o `304` diz que nada mudou, e
     * a única coisa que ele traz de novo é o token.
     */
    /**
     * O token é guardado **como o provedor o mandou**, e a forma é só conferida.
     *
     * A comparação do UUID é a validação; o que volta é o valor original. Um
     * `strtolower()` aqui seria mexer nos bytes de uma credencial que é
     * devolvida ao provedor como cabeçalho em toda chamada — e se um dia o
     * provedor passar a comparar o token que recebeu com o que tem guardado, a
     * comparação falha por nossa causa, num `401` que ninguém entenderia.
     */
    public function test_o_token_do_etag_e_gravado_exatamente_como_o_provedor_o_mandou(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG_MAIUSCULO]);

        $renovado = $manager->refresh($conta->getKey());

        $this->assertSame('8F68D948-1059-4F42-9AA6-2931670B0A80', $renovado->token());
    }

    /**
     * Uma validade que não existe não pode virar uma validade.
     *
     * **O modo como isso falha é medido, e é por transbordo de campos, não por
     * "agora".** `Carbon::createFromFormat('Y-m-d\TH:i:s', '2026-13-45T99:99:99')`
     * não lança: o PHP transborda mês 13, dia 45 e hora 99:99:99 e devolve uma
     * data real, `2023-02-18 07:40:39` em UTC. O efeito seria um token com
     * validade em fevereiro do ano seguinte — e `validToken()` o servindo,
     * porque um instante futuro é um instante válido. `DateTime::getLastErrors()`
     * é o que separa "a data que o provedor mandou" de "a data que o PHP
     * inventou a partir de uma que não existe".
     *
     * Os dois formatos são exercitados, porque a entrada é a mesma e a defesa
     * precisa estar nos dois: o `expires` do `304` e o `data_hora_expiracao` do
     * `200`.
     */
    public function test_uma_validade_que_nao_existe_nao_vira_instante(): void
    {
        Carbon::setTestNow('2026-03-10 09:30:00');

        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();

        // O caminho do `200`, com a validade impossível em `dados`.
        $this->respondeApoiar(200, [
            'status' => 200,
            'dados' => json_encode([
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => self::DATA_IMPOSSIVEL,
            ], JSON_THROW_ON_ERROR),
            'mensagens' => [['codigo' => '200', 'texto' => 'Sucesso na execução.']],
        ]);

        $manager = resolve(SerproTermManager::class);
        $emitido = $manager->issue($conta->getKey());

        $this->assertNull($emitido->token_expires_at, 'Uma data impossível não pode virar um instante.');
        $this->assertNull($manager->validToken($conta->getKey()));

        // O caminho do `304`, com o `expires` fora do formato de data HTTP.
        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG, 'Expires' => self::EXPIRES_IMPOSSIVEL]);

        $renovado = $manager->refresh($conta->getKey());

        $this->assertNull($renovado->token_expires_at);
        $this->assertNull($manager->validToken($conta->getKey()));
    }

    /**
     * Um `304` sem validade utilizável **não** deixa o termo se dizendo
     * autenticado.
     *
     * O estado é o que a tela mostra, e `autenticado` afirma ao escritório que
     * a plataforma fala por ele. Ao mesmo tempo, `validToken()` recusa servir um
     * token sem validade, então a linha dizia uma coisa e o sistema fazia outra
     * — e a tela do termo, que é a única que o escritório vê, era a que mentia.
     *
     * O estado correto é `validado`: o provedor aceitou o documento, e nós não
     * temos token que valha. É a mesma leitura que o `200` sem token já recebe,
     * e a frase do motivo diz que a ausência é da validade, não do documento.
     */
    public function test_um_304_sem_validade_utilizavel_nao_deixa_o_termo_autenticado(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG]);

        $renovado = $manager->refresh($conta->getKey());

        $this->assertSame(SerproAuthorizationTermState::Validado, $renovado->state);
        $this->assertStringContainsString('não pode usar', (string) $renovado->state_reason);
        $this->assertStringContainsString('sem token em uso', (string) $renovado->state_reason);
        $this->assertNull($renovado->token_expires_at);
        $this->assertNull($manager->validToken($conta->getKey()));
    }

    /**
     * Uma validade **bem formada e já passada** é o mesmo caso de uma validade
     * ausente, e a linha não pode dizer que o termo está autenticado.
     *
     * **Por que o caso é realista e não um contorno de teste.** O provedor
     * documenta o `304` como "não modificado, o token estava em cache" — e a
     * validade de um token em cache é, por construção, a do token **original**,
     * que é a que já passou. Um `Expires` de ontem é o `Expires` que a renovação
     * real vai encontrar, e o estado que a versão anterior gravava era
     * `autenticado` com `validToken()` devolvendo `null`: a tela dizia que a
     * plataforma fala pelo escritório no mesmo instante em que o sistema se
     * recusava a falar.
     *
     * O caminho do `200` recebe o mesmo tratamento e por metade do motivo: ali a
     * validade **nem era lida** para decidir o estado — um `200` com token e
     * `data_hora_expiracao` impossível virava `autenticado` com
     * `token_expires_at` nulo, que é a mesma mentira com menos informação ainda.
     */
    public function test_uma_validade_ja_passada_nao_deixa_o_termo_autenticado(): void
    {
        Carbon::setTestNow('2026-03-10 09:30:00');

        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($conta->getKey());

        // O `304` com a validade de ontem: bem formada, e vencida. O valor
        // vem do próprio exemplo do provedor, que é de 2022.
        $this->respondeApoiar(304, '', [
            'ETag' => self::TOKEN_ETAG,
            'Expires' => self::EXPIRES_HTTP,
        ]);

        $renovado = $manager->refresh($conta->getKey());

        $this->assertSame(
            SerproAuthorizationTermState::Validado,
            $renovado->state,
            'Uma validade vencida não pode deixar a linha se dizendo autenticado.',
        );
        $this->assertStringContainsString('sem token em uso', (string) $renovado->state_reason);
        $this->assertNull($manager->validToken($conta->getKey()));

        // O instante que o provedor mandou continua gravado: ele é um fato, e
        // apagá-lo tiraria da linha a única pista de que a validade existiu.
        $this->assertSame('2022-10-15 00:00:01', $renovado->token_expires_at?->format('Y-m-d H:i:s'));

        // E o caminho do `200`, com a validade vencida em `dados`.
        $this->respondeApoiar(200, [
            'status' => 200,
            'dados' => json_encode([
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => '2026-03-09T00:00:01',
            ], JSON_THROW_ON_ERROR),
            'mensagens' => [['codigo' => '200', 'texto' => 'Sucesso na execução.']],
        ]);

        $emitido = $manager->issue($conta->getKey());

        $this->assertSame(
            SerproAuthorizationTermState::Validado,
            $emitido->state,
            'Um token com validade vencida não autentica o termo.',
        );
        $this->assertStringContainsString('sem token em uso', (string) $emitido->state_reason);
        $this->assertNull($manager->validToken($conta->getKey()));
    }

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
        //
        // A resposta da renovação é o `304` com validade no futuro, e não a
        // aceitação: o relógio do caso anda um mês inteiro, e a validade que o
        // provedor mandou na emissão está vencida há muito tempo — o que é
        // verdade também no produto, e é o que deixa a linha `validado`.
        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG, 'Expires' => self::EXPIRES_HTTP_FUTURO]);

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

    /**
     * **O provedor respondeu, e a resposta é "mande outro termo".**
     *
     * `AcessoNegado-ICGERENCIADOR-020` e `-042` classificam como
     * `SerproFailure::ResubmitTerm`, e é a única resposta do taxonomy em que o
     * provedor fez o trabalho — leu o documento, o avaliou e concluiu que este
     * não serve. Tratar isso como indisponibilidade deixava a linha dizendo
     * `autenticado`, servindo um token que o provedor não aceita, e a renovação
     * diária repetindo a mesma recusa para sempre, com a mensagem dizendo que o
     * provedor não confirmou o envio — o oposto do que aconteceu.
     *
     * **O motivo tem de dizer a ação, e a ação é re-assinar.** A linha carrega
     * o código do provedor e a frase que diz o que fazer; o texto que o provedor
     * escreveu sobre a requisição não entra, pelo mesmo motivo de sempre.
     */
    public function test_o_pedido_de_um_termo_novo_e_uma_recusa_que_a_linha_registra(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();

        $this->fakeApoiarRecusa('AcessoNegado-ICGERENCIADOR-042', 403, 'envie um termo novo assinado.');

        try {
            resolve(SerproTermManager::class)->issue($conta->getKey());
            $this->fail('O pedido de um termo novo tem de subir como SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::ResubmitTerm, $exception->failure);
            $this->assertSame('AcessoNegado-ICGERENCIADOR-042', $exception->providerCode);
        }

        $termo = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();

        $this->assertSame(SerproAuthorizationTermState::Recusado, $termo->state);
        $this->assertStringContainsString('AcessoNegado-ICGERENCIADOR-042', (string) $termo->state_reason);
        $this->assertStringContainsString('assinado de novo', (string) $termo->state_reason);
        $this->assertStringNotContainsString('envie um termo novo assinado.', (string) $termo->state_reason);

        // E o token não é servido: a linha recusa, e `validToken()` concorda.
        $this->assertNull(resolve(SerproTermManager::class)->validToken($conta->getKey()));
    }

    /**
     * A renovação **não** reenvia um termo que o provedor já recusou.
     *
     * Reenviar o mesmo documento para um provedor que respondeu "este não serve"
     * não pode dar outro resultado — e a renovação diária rodando uma vez por
     * dia por conta transformaria um defeito permanente em consumo de cota e um
     * `log` por dia por escritório. O caminho é o mesmo do termo vencido: a
     * linha diz o que está errado e nada sai.
     *
     * O motivo de o conserto ser o escritório re-assinar, e não a plataforma
     * reenviar, é que o documento recusado **é** o documento que o provedor
     * leu e rejeitou: reenviar os mesmos bytes é a única coisa que o provedor
     * pode ver, e ele já respondeu sobre eles.
     */
    public function test_a_renovacao_nao_reenvia_um_termo_que_o_provedor_ja_recusou(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarRecusa('AcessoNegado-ICGERENCIADOR-042', 403);

        $manager = resolve(SerproTermManager::class);

        try {
            $manager->issue($conta->getKey());
        } catch (SerproException) {
            // A recusa da emissão é o que prepara o terreno; o que este caso
            // afirma é o que a renovação faz depois dela.
        }

        $antes = count(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/Apoiar')));

        $renovado = $manager->refresh($conta->getKey());

        $depois = count(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/Apoiar')));

        $this->assertSame($antes, $depois, 'A renovação de um termo recusado não pode chamar o provedor.');
        $this->assertSame(SerproAuthorizationTermState::Recusado, $renovado->state);
        $this->assertStringContainsString('assinado de novo', (string) $renovado->state_reason);
        $this->assertNull($manager->validToken($conta->getKey()));
    }

    /**
     * Um termo recusado cujo documento também venceu continua recusado, e o
     * código da recusa sobrevive.
     *
     * **Os dois estados mandam o escritório a agir, e é por isso que a ordem
     * importa.** `vencido` e `recusado` pedem a mesma coisa — um termo novo
     * assinado —, e por isso a tela não os distingue na ação. O que o operador
     * distingue é a **causa**, e a causa de um `recusado` é o código que o
     * provedor devolveu: é o único registro de *por que* aquele documento não
     * serve, e ele é o que separa "o documento está velho" de "o documento está
     * errado". Sobrescrever a recusa por `Vencido` apagava o diagnóstico para
     * ganhar uma distinção que a ação não usa.
     *
     * O caso monta as duas condições de uma vez porque é a ordem do código que
     * decide: o vencimento era conferido primeiro, e a recusa só era lida depois
     * que o documento já tinha virado `vencido`.
     */
    public function test_a_renovacao_de_um_termo_recusado_e_vencido_guarda_o_codigo_da_recusa(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarRecusa('AcessoNegado-ICGERENCIADOR-042', 403);

        $manager = resolve(SerproTermManager::class);

        try {
            $manager->issue($conta->getKey());
        } catch (SerproException) {
            // A recusa da emissão é o que produz o estado recusado; o que este
            // caso afirma é o que a renovação faz com ele depois.
        }

        $termo = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();

        $this->assertSame(SerproAuthorizationTermState::Recusado, $termo->state);

        // O documento vence depois da recusa: os dois estados valem ao mesmo
        // tempo, e é a ordem das conferências que decide qual fica.
        $termo->forceFill(['document_expires_on' => now()->subDay()->startOfDay()])->save();

        $renovado = $manager->refresh($conta->getKey());

        $this->assertSame(
            SerproAuthorizationTermState::Recusado,
            $renovado->state,
            'A recusa do provedor é o diagnóstico da linha e não pode ser sobrescrita pelo vencimento.',
        );
        $this->assertStringContainsString('AcessoNegado-ICGERENCIADOR-042', (string) $renovado->state_reason);
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

        /*
         * **O `fill()` é conferido com `isDirty`, e não com
         * `getRawOriginal()`.** A versão anterior lia o valor original depois
         * do `fill()` e o comparava com ele mesmo: `getRawOriginal()` devolve a
         * cópia que veio do banco, que um `fill()` — que só toca atributo em
         * memória — não altera. O caso passaria com as duas colunas no
         * `#[Fillable]`, e a garantia que ele existe para provar seria a
         * garantia que ele não prova. `isDirty()` lê o atributo atual contra o
         * original, e é por isso que ele pega a volta.
         *
         * E o `isFillable()` logo acima é a garantia real: `fill()` só recusa o
         * que não está na lista, e a lista é o que precisa estar ausente.
         */
        $conexao->fill([
            'term_format_sha256' => hash('sha256', 'preenchido-por-uma-requisição'),
            'term_format_proven_at' => now(),
            'consumer_secret_encrypted' => 'segredo-inventado',
        ]);

        $this->assertFalse($conexao->isDirty('term_format_sha256'), 'A prova não pode ser preenchida por atribuição em massa.');
        $this->assertFalse($conexao->isDirty('term_format_proven_at'), 'A prova não pode ser preenchida por atribuição em massa.');
        $this->assertFalse($conexao->isDirty('consumer_secret_encrypted'), 'O segredo não pode ser preenchido por atribuição em massa.');
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

    /**
     * O registro da prova declara **o que** é preenchível, e não o contrário.
     *
     * O model usava `$guarded = []`, que é atribuição em massa aberta para
     * qualquer coluna — inclusive `id` e `created_at`, que são do banco. Num
     * recurso cuja premissa é que o `#[Fillable]` é uma camada de segurança, o
     * model de auditoria sendo o único mais permissivo de `app/Models/` é o
     * avesso da ordem de risco: a linha que guarda "alguém afirmou, com um
     * digest e uma hora, que o provedor aceita o documento" é mais sensível ao
     * que o modelo de dado do termo, não menos.
     *
     * A lista fechada é o que o comando popula, e o que a testagem prova: cada
     * coluna gravável é preenchível, e `id` e `created_at` não são.
     */
    public function test_o_registro_da_prova_declara_cada_coluna_gravavel(): void
    {
        foreach ([
            'term_format_sha256',
            'recorded_by',
            'reason',
            'superseded_sha256',
            'superseded_at',
        ] as $coluna) {
            $this->assertTrue(
                (new SerproTermProofRecord)->isFillable($coluna),
                "A coluna {$coluna} é gravada pelo comando e tem de ser preenchível.",
            );
        }

        foreach (['id', 'created_at'] as $coluna) {
            $this->assertFalse(
                (new SerproTermProofRecord)->isFillable($coluna),
                "A coluna {$coluna} é do banco e não pode ser preenchida por atribuição em massa.",
            );
        }
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
        // `BelongsToAccount` que esconderia a linha de quem o teste está
        // relacionando.
        $this->assertSame(
            self::TOKEN_ETAG,
            SerproAuthorizationTerm::query()->where('account_id', $alvo->getKey())->sole()->token(),
        );
        $this->assertSame(
            self::TOKEN,
            SerproAuthorizationTerm::query()->withoutGlobalScopes()->where('account_id', $outro->getKey())->sole()->token(),
        );
    }

    /**
     * O gestor lê a conta do job, e não a do `queue:work` que ficou.
     *
     * O caso acima passa pelo `RenewSerproTermsJob`, que **recarrega** o
     * singleton antes de chamar o gestor — as duas camadas fecham o mesmo
     * problema, e por isso ele não veria este defeito. Aqui o gestor é chamado
     * direto, com um `CurrentTenant` que aponta para outra conta: é o estado em
     * que o `queue:work` fica depois de um job anterior e antes do próximo.
     *
     * **O efeito sem a correção é uma mensagem falsa.** O escopo global de
     * `BelongsToAccount` filtra a leitura pelo singleton, a linha da conta
     * pedida desaparece da consulta e o gestor responds que a conta "ainda não
     * tem termo de autorização emitido" — ou que "ainda não entregou o e-CNPJ",
     * para o certificado. Nenhuma das duas frases é verdade, e ambas mandam o
     * escritório fazer o que ele já fez.
     */
    public function test_o_gestor_ignora_o_tenant_que_sobrou_e_atende_a_conta_do_job(): void
    {
        [$alvo] = $this->escritorio('Escritório Alvo');
        [$vizinha] = $this->escritorio('Escritório Vizinha');

        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $manager->issue($alvo->getKey());
        $manager->issue($vizinha->getKey());

        // O que sobrou do job anterior, e que ninguém reseta.
        resolve(CurrentTenant::class)->accountId = $vizinha->getKey();

        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG, 'Expires' => self::EXPIRES_HTTP]);

        $renovado = $manager->refresh($alvo->getKey());

        $this->assertSame(self::TOKEN_ETAG, $renovado->token());
        $this->assertSame(
            self::TOKEN,
            SerproAuthorizationTerm::query()->withoutGlobalScopes()->where('account_id', $vizinha->getKey())->sole()->token(),
            'A conta vizinha não pode ter o token trocado por um job que não é dela.',
        );

        // E o mesmo para o e-CNPJ, que é a leitura que produz a mensagem mais
        // enganosa das duas — "o escritório nunca entregou certificado".
        resolve(CurrentTenant::class)->accountId = $vizinha->getKey();

        $emitido = $manager->issue($alvo->getKey());

        $this->assertSame(
            $alvo->getKey(),
            $emitido->account_id,
            'A emissão é da conta do job, e não da conta que o singleton carrega.',
        );
        $this->assertSame(self::TOKEN_ETAG, $emitido->token());
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

    /**
     * A travessia cobre a carteira **inteira** sob `QUEUE_CONNECTION=sync`.
     *
     * **Por que este caso existe e o anterior não o substitui.** O caso acima
     * usa `Queue::fake()` e uma conta só: com a fila dublê, o job não roda, o
     * `CurrentTenant` não é tocado por ninguém, e uma página só não tem segunda
     * volta. Sob `sync` — o do `phpunit.xml` e um dos possíveis numa instalação
     * — o primeiro job executa dentro do `dispatch()` e **carrega o singleton da
     * conta dele**; a consulta da página seguinte é reavaliada, o escopo global de
     * `BelongsToAccount` filtra por aquela conta, `id > <último da página>` não
     * devolve nada, o laço para e o comando anuncia a carteira coberta.
     *
     * O defeito é silencioso **e parece sucesso**: a contagem sai menor e o
     * operador não tem como saber que a última página não foi renewada. Por isso
     * a afirmação é sobre as **linhas**, e não só sobre a contagem impressa.
     *
     * 101 termos é o menor número que produz duas páginas: o `chunkById` pede
     * 100, e um termo a mais é exatamente a segunda página que a versão
     * anterior perdia.
     */
    public function test_a_travessia_da_renovacao_cobre_a_carteira_inteira_com_despacho_sincrono(): void
    {
        $this->assertSame('sync', config('queue.default'), 'O caso mede a travessia sob a fila síncrona.');

        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG]);

        $contas = [];

        for ($indice = 0; $indice < 101; $indice++) {
            $conta = Account::factory()->create(['name' => 'Escritório '.$indice]);
            $this->termoProntoParaRenovar($conta);
            $contas[] = $conta->getKey();
        }

        $this->artisan('serpro:renew-terms')
            ->expectsOutput('Renovações despachadas: 101')
            ->assertSuccessful();

        // O carimbo do envio aceito é o sinal: ele só é gravado quando o
        // provedor aceitou o documento, e a linha nasce sem ele.
        $renovados = SerproAuthorizationTerm::query()
            ->withoutGlobalScopes()
            ->whereIn('account_id', $contas)
            ->whereNotNull('last_submitted_at')
            ->count();

        $this->assertSame(
            101,
            $renovados,
            'A travessia anuncieu a carteira coberta e deixou a última página sem renovar: o escopo de conta reavaliou o singleton que o primeiro job carregou.',
        );
    }

    /**
     * Uma falha no meio da travessia deixa o rótulo e a classe, e a agenda de
     * amanhã cobre quem faltou.
     *
     * **O que mudou e o que não.** A versão anterior declarava um `failed()` que
     * **nada chama** — o framework só o invoca nos jobs enfileirados, e um
     * comando de agenda não é um — e a docblock prometia um log de rótulo e
     * classe. O que acontecia de verdade era o `ScheduleRunCommand` capturar a
     * exceção e chamar `report($e)`: mensagem inteira e stack trace, que é o
     * oposto do que a frase prometia.
     *
     * A correção é a curadoria dentro do próprio `handle()`, e o caso mostra as
     * duas metades: a linha que entra no log não carrega o documento que a
     * exceção carregava, e o comando **não** sai com sucesso — uma travessia
     * interrompida no meio é uma falha da agenda, e dizer que correu bem é a
     * mesma mentira que a contagem errada da travessia.
     */
    public function test_a_falha_no_meio_da_travessia_registra_o_rotulo_e_nao_diz_que_deu_certo(): void
    {
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->respondeApoiar(304, '', ['ETag' => self::TOKEN_ETAG]);

        $primeira = Account::factory()->create(['name' => 'Escritório que renova']);
        $this->termoProntoParaRenovar($primeira);

        $quebrada = Account::factory()->create(['name' => 'Escritório ilegível']);
        $this->termoProntoParaRenovar($quebrada)->forceFill(['document_encrypted' => ''])->save();

        $registros = [];

        Log::spy();

        $codigo = $this->artisan('serpro:renew-terms')->run();

        Log::shouldHaveReceived('error')->atLeast()->once()->withArgs(
            function (string $mensagem, array $contexto) use (&$registros): bool {
                if (! str_contains($mensagem, 'renovação diária dos termos')) {
                    return false;
                }

                $registros[] = $mensagem.json_encode($contexto, JSON_UNESCAPED_UNICODE);

                return true;
            },
        );

        $this->assertNotSame([], $registros, 'A falha da travessia não deixou a linha curada que a docblock prometia.');

        foreach ($registros as $registro) {
            $this->assertStringNotContainsString('<termoDeAutorizacao', $registro);
            $this->assertStringNotContainsString(self::TOKEN, $registro);
            $this->assertStringNotContainsString(self::SENHA, $registro);
        }

        $this->assertSame(
            Command::FAILURE,
            $codigo,
            'Uma travessia interrompida não pode sair com sucesso: é a agenda que precisa saber que a carteira não foi coberta.',
        );

        // A conta que vinha antes da quebrada foi renovada: a falha interrompe a
        // travessia, e não desfaz o que a travessia já fez.
        $this->assertSame(
            self::TOKEN_ETAG,
            SerproAuthorizationTerm::query()->withoutGlobalScopes()->where('account_id', $primeira->getKey())->sole()->token(),
        );
    }

    /**
     * A falha do job de renovação diz **por que**, e só diz quando a frase é
     * nossa.
     *
     * **A indisponibilidade do provedor é o caso que o rótulo sozinho não
     * explica.** Ela não grava estado nenhum — a linha continua com o token que
     * valia —, de modo que o único registro da falha é este log, e um log com o
     * rótulo e a classe da exceção diz "algo falhou" sem dizer o quê. A
     * `SerproException` do `SerproTermManager` tem a frase certa em cada ponto.
     *
     * A segunda metade é a que impede o conserto de virar vazamento: uma
     * exceção que **não** é `SerproException` pode carregar o texto do OpenSSL
     * ou o de uma biblioteca, e a frase dela não pode entrar no log. O caso
     * passa as duas pelo mesmo gancho e exige a diferença.
     */
    public function test_a_falha_do_job_de_renovacao_leva_a_frase_nossa_e_nao_a_de_terceiros(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');

        $comMotivo = [];
        $semMotivo = '';

        Log::spy();

        (new RenewSerproTermsJob($conta->getKey()))->failed(new SerproException(
            'O provedor não confirmou o envio do termo de autorização; o estado que a linha já tinha foi preservado.',
            SerproFailure::Upstream,
            503,
        ));

        (new RenewSerproTermsJob($conta->getKey()))->failed(new RuntimeException('error:0909006C:PEM routines:get_name:no start line'));

        Log::shouldHaveReceived('error')->twice()->withArgs(
            function (string $mensagem, array $contexto) use (&$comMotivo, &$semMotivo): bool {
                if ($contexto['motivo'] !== null) {
                    $comMotivo[] = $contexto['motivo'];

                    return true;
                }

                $semMotivo = $mensagem.json_encode($contexto, JSON_UNESCAPED_UNICODE);

                return true;
            },
        );

        $this->assertCount(1, $comMotivo, 'A falha do provedor precisa deixar a frase que o operador vai ler.');
        $this->assertStringContainsString('não confirmou o envio', $comMotivo[0]);
        $this->assertStringNotContainsString('PEM routines', $semMotivo, 'A mensagem de uma exceção que não é nossa não pode entrar no log.');
        $this->assertStringNotContainsString('<Signature', $semMotivo);
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

    /**
     * `last_submitted_at` é carimbado **quando o provedor aceita**, e não
     * quando o envio é tentado.
     *
     * A coluna é o que responde "a integração está viva". Carimbá-la antes da
     * chamada faria a linha registrar uma **intenção**, e no caso da recusa
     * deixaria `recusado` com um instante de agora — o estado dizendo que o
     * documento não serve e a coluna dizendo que o envio foi bem-sucedido há um
     * minuto, no mesmo registro.
     *
     * O caso é montado pelo caminho que importa: um termo **já aceito** que é
     * reenviado e recusado. O carimbo do envio aceito tem de sobreviver à
     * recusa, porque ele continua dizendo a verdade sobre a última vez que o
     * provedor aceitou este documento.
     */
    public function test_o_carimbo_do_envio_aceito_sobe_e_a_recusa_nao_o_move(): void
    {
        Carbon::setTestNow('2026-03-10 09:30:00');

        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $manager = resolve(SerproTermManager::class);
        $emitido = $manager->issue($conta->getKey());

        $this->assertNotNull($emitido->last_submitted_at, 'O envio aceito tem de carimbar a coluna.');
        $aceito = $emitido->last_submitted_at->format('Y-m-d H:i:s');

        // A renovação responde pedindo outro termo, três dias depois.
        Carbon::setTestNow('2026-03-13 08:00:00');
        $this->fakeApoiarRecusa('AcessoNegado-ICGERENCIADOR-020', 403);

        try {
            $manager->refresh($conta->getKey());
        } catch (SerproException) {
            // A recusa sobe; o que este caso afirma é o que ela deixou na linha.
        }

        $recusado = SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole();

        $this->assertSame(SerproAuthorizationTermState::Recusado, $recusado->state);
        $this->assertSame(
            $aceito,
            $recusado->last_submitted_at?->format('Y-m-d H:i:s'),
            'A recusa não pode mover o carimbo do último envio aceito.',
        );
    }

    /**
     * Um termo recusado na **primeira** tentativa não tem carimbo de envio, e é
     * o que a coluna anulável diz.
     *
     * O documento acabou de ser assinado e nenhum envio foi aceito; um carimbo
     * de agora registraria uma tentativa cujo desfecho é a recusa ao lado, e a
     * coluna anulável existe exatamente para este estado.
     */
    public function test_um_termo_recusado_na_primeira_tentativa_nao_tem_carimbo_de_envio(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarRecusa('AcessoNegado-AUTENTICAPROCURADOR-019', 403);

        try {
            resolve(SerproTermManager::class)->issue($conta->getKey());
        } catch (SerproException) {
            // A recusa sobe; o carimbo é o que este caso mede.
        }

        $this->assertNull(
            SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->sole()->last_submitted_at,
            'Nenhum envio foi aceito, e a coluna diz isso.',
        );
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
     * A emissão com o gate fechado **não levanta**, e é o caso que fixa a
     * regressão da fila `sync`.
     *
     * **O que este caso afirma, e é o que ele faz:** o `handle()` do
     * `IssueSerproTermJob` **volta** quando a emissão é recusada, nenhuma linha
     * de termo é gravada e nada sai para o provedor. Sem `expectException` de
     * propósito — a exceção escaping é o defeito, e um `expectException` a
     * transformaria em afirmação.
     *
     * **E o que ele não afirma, porque é o que a docblock anterior afirmava:**
     * este caso não toca em upload, não grava certificado e não lê o termo
     * pela rota. Ele chama o job **direto**, e é por isso que ele não depende
     * de a fila rodar: o `handle()` é chamado aqui, sem `Queue::fake` e sem
     * depender do `afterCommit`.
     *
     * **A docblock que este caso tinha antes afirmava duas coisas que eram do
     * caso de baixo** — que ele via "o `200` do upload, o certificado gravado e
     * o termo ausente", e que sob `QUEUE_CONNECTION=sync` a falha atravessada
     * transformaria o `200` em erro. Nenhuma das duas era deste caso, e a
     * segunda é pior do que falsa: naquele caso o job **não roda**, porque o
     * `afterCommit` fica pendurado na transação que o `RefreshDatabase`
     * substitui — a medida disso é a docblock do caso de baixo. O `200` vinha
     * desse fato, o `assertNull(...)` era trivialmente verdadeiro, e o caso
     * passava com e sem o `try`/`catch`, enquanto a regressão que ele fingia
     * cobrir derrubaria o upload de e-CNPJ de **toda** conta enquanto o gate
     * estivesse fechado.
     */
    public function test_a_emissao_com_o_gate_fechado_nao_levanta_e_nao_grava_nada(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');

        $this->assertSame(SerproTermProof::Ausente, SerproConnection::sole()->termProof());

        // Sem `expectException`: o `handle()` do job tem de **voltar**. É este
        // o caso que fixa a regressão da fila `sync` — o `try`/`catch` do job,
        // sem o qual uma emissão recusada atravessaria o upload e o transformaria
        // em `500`.
        (new IssueSerproTermJob($conta->getKey()))->handle(resolve(SerproTermManager::class));

        $this->assertDatabaseCount('serpro_authorization_terms', 0);
        $this->assertNull(
            SerproAuthorizationTerm::query()->withoutGlobalScopes()->where('account_id', $conta->getKey())->first(),
        );
        Http::assertNothingSent();
    }

    /**
     * O gate fechado **não** pode virar `500` no upload do e-CNPJ.
     *
     * **Este é o complemento do caso `test_a_emissao_com_o_gate_fechado_nao_levanta_e_nao_grava_nada`,
     * e não a prova dele.** Com
     * `QUEUE_CONNECTION=sync` — o do `phpunit.xml` e um dos possíveis em
     * instalação — o `afterCommit` do `IssueSerproTermJob` é registrado no
     * nível de transação que o `RefreshDatabase` substitui, e o nível 0 não é
     * confirmado dentro do teste: o job **não roda**. O `200` deste caso vem
     * desse fato, e o `assertNull(...)` é trivialmente verdadeiro; remover o
     * `try`/`catch` do job não o quebraria. O que o outro caso prova, chamando
     * o `handle()` de verdade, é que a emissão recusada sobrevive — e é o que
     * o mutant test dele mede.
     *
     * O que este caso afirma, e é dele: o e-CNPJ do escritório é gravado e a
     * leitura do termo responde `ausente` com o gate fechado — que é a
     * informação que manda a tela pedir o certificado em vez de mostrar um erro.
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

    /**
     * Nenhuma coluna cifrada sai pela serialização da linha inteira.
     *
     * A resource lista quatro campos e é a primeira rede; o `#[Hidden]` do
     * model é a segunda, e ela existe para o caso de alguém serializar a linha
     * sem passar pela resource — o `return $term` de um controller é o
     * acidente que ela cobre. Sem este caso o atributo é uma afirmação de
     * docblock: apagar o `#[Hidden]` deixaria a suíte verde, e o documento
     * assinado e o token passariam a poder vazar por `toArray()`.
     */
    public function test_nenhuma_coluna_cifrada_sai_pela_serializacao_da_linha(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $termo = resolve(SerproTermManager::class)->issue($conta->getKey());
        $linha = $termo->fresh()->toArray();

        $this->assertArrayNotHasKey('document_encrypted', $linha);
        $this->assertArrayNotHasKey('token_encrypted', $linha);

        // E o que sai é o metadado, para o teste não passar por uma linha vazia.
        $this->assertArrayHasKey('account_id', $linha);
        $this->assertSame($conta->getKey(), $linha['account_id']);
    }

    /**
     * O **manager** lê a credencial de plataforma uma vez só.
     *
     * **A medição para no gate, e é por isso que ela é honesta.** Uma emissão
     * completa lê a linha **duas** vezes com o par de tokens em cache — o
     * manager e o `SerproClient::submitTerm` —, e uma terceira quando o par não
     * está em cache, porque o `SerproTokenProvider` precisa do `consumer_key` da
     * credencial para autenticar. As leituras do transporte são **deliberadas**:
     * ele relê a linha para conferir a identidade do contratante contra o
     * certificado que vai materializar, e relê-la na hora do uso é o que protege
     * contra uma credencial trocada entre a checagem e a chamada.
     *
     * **Uma quarta leitura existia, e ela é o defeito.** O `SerproTermSigner`
     * recebia o número do contratante por parâmetro e relia a credencial para
     * achar o nome — de modo que o `destinatario` do documento vinha de uma
     * linha diferente da que o gate liberou. A correção foi fazer a linha
     * viajar para o assinante, e é o caso
     * `test_o_termo_e_assinado_com_a_credencial_que_o_gate_decidiu` que mede a
     * consequência; este mede a quantidade no caminho do gate.
     *
     * Com o gate fechado, `issue()` para antes do transporte e a contagem é do
     * manager sozinho — e é exatamente a linha que a versão anterior lia duas
     * vezes.
     */
    public function test_o_manager_lua_a_credencial_de_plataforma_uma_vez_so(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');

        // Gate fechado de propósito: a emissão recusa antes de qualquer
        // transporte, e a leitura da credencial é a única que o manager faz.
        $this->assertSame(SerproTermProof::Ausente, SerproConnection::sole()->termProof());

        $this->contando = true;
        $this->leituras = 0;

        try {
            resolve(SerproTermManager::class)->issue($conta->getKey());
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
        } finally {
            $this->contando = false;
        }

        $this->assertSame(1, $this->leituras, 'O manager lê a credencial de plataforma uma vez por emissão.');
    }

    /**
     * O termo é assinado com a credencial **que o gate decidiu**, e o caminho
     * inteiro da emissão confirma isso.
     *
     * **O caso mede a consequência, e não a quantidade de leituras.** A
     * credencial é trocada no instante em que a primeira leitura dela acontece:
     * a partir daí, quem reler a linha encontra outra empresa. Com a leitura
     * única, o `destinatario` continua sendo a plataforma que o gate liberou;
     * com a releitura, o termo sai assinado endereçado a quem entrou no meio —
     * que é exatamente o desfecho que a docblock do manager diz que impede e que
     * o `SerproTermSigner` desfazia ao reler a linha para o nome.
     *
     * Contar leituras não fecharia o caso: o transporte relê a linha de novo
     * por decisão própria e legítima, e o número total de leituras de
     * `serpro_connections` de uma emissão é maior do que um. O que a contagem
     * do caso acima mede é o **gestor sozinho**, e ela é complemento
     * deste: uma lê, o outro prova que ler uma vez basta.
     */
    public function test_o_termo_e_assinado_com_a_credencial_que_o_gate_decidiu(): void
    {
        [$conta] = $this->escritorio('Escritório de Teste');
        $this->provaDeContrato();
        $this->fakeTokenAutenticado();
        $this->fakeApoiarAceito();

        $this->rotacionando = true;

        $termo = resolve(SerproTermManager::class)->issue($conta->getKey());

        $this->assertTrue($this->rotacionado, 'A rotação da credencial não disparou: o caso não mediu nada.');

        $documento = $this->documentoDe($termo);

        $this->assertSame(
            'Plataforma de Teste',
            $this->atributo($documento, 'destinatario', 'nome'),
            'O destinatário do termo tem de ser a plataforma que o gate liberou, e não a que entrou na rotação.',
        );
        $this->assertNotSame(self::OUTRA_PLATAFORMA, $this->atributo($documento, 'destinatario', 'nome'));

        // E o número continua sendo o da credencial — a conferência de
        // identidade do transporte a teria reprovado se não fosse.
        $this->assertSame(self::PLATAFORMA, $this->atributo($documento, 'destinatario', 'numero'));
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

    // ------------------------------------------------------------------ a policy

    /**
     * A leitura é do Membro da conta, e só dela.
     *
     * `HasTenantRole` é o que torna isto uma policy e não um `return true`
     * embrulhado: sem ele, um usuário sem vínculo com a conta corrente
     * passaria, e a guarda de tenancy teria de estar em outro lugar — que é o
     * que a review aponta como a razão de `AccountPolicy` e
     * `SerproConnectionPolicy` omitirem o trait sem serem um contraexemplo: elas
     * são de plataforma, e esta é de tenant.
     */
    public function test_a_policy_le_o_termo_do_membro_da_conta_e_recusa_quem_nao_e_membro(): void
    {
        [$dona] = $this->escritorio('Escritório Dona');
        [$vizinha] = $this->escritorio('Escritório Vizinha');

        $membroDaConta = $this->membroDe($dona, 'user');
        $deOutraConta = $this->membroDe($vizinha, 'admin');

        // O tenant corrente é o da dona nos dois lados da comparação: o que muda
        // é o **vínculo do usuário** com ele, que é o que a policy mede.
        resolve(CurrentTenant::class)->accountId = $dona->getKey();

        $this->assertTrue(Gate::forUser($membroDaConta)->allows('viewAny', SerproAuthorizationTerm::class));

        // O usuário de outra conta, com a conta da dona como tenant corrente,
        // não tem vínculo com ela — e é recusado.
        $this->assertFalse(Gate::forUser($deOutraConta)->allows('viewAny', SerproAuthorizationTerm::class));

        // E um usuário sem nenhuma conta, que é o super_admin fora de qualquer
        // vínculo também cai fora.
        $semVinculo = User::factory()->create();

        $this->assertFalse(Gate::forUser($semVinculo)->allows('viewAny', SerproAuthorizationTerm::class));
    }

    /**
     * Nenhum Membro escreve o termo, e o que impede não é um `return false`
     * e sim a **ausência** do verbo.
     *
     * O termo é assinado com o e-CNPJ do escritório, que nenhum Membro tem, e
     * o design diz que a emissão é automática e nunca pede assinatura a
     * ninguém. Não há rota de escrita, e **método sem rota é método que ninguém
     * exercita** — a mesma regra que a `AccountCertificatePolicy` escreve na
     * própria docblock. A garantia é o Gate negando por omissão, e ela só é
     * verificável se o verbo continuar ausente: um `create` que voltasse
     * devolveria `false` ainda, mas a policy voltaria a ter um método que
     * ninguém chama e que o próximo autor pode ler como "escrita permitida para
     * quem passar por aqui".
     *
     * `resolve()` de uma policy inexistente ainda nega, então a ausência de
     * `create` é o que fixa a **estrutura**, e o `allows(...) === false` fixa o
     **comportamento** — e o `viewAny` verdadeiro do caso anterior é o que
     * impede que a policy inteira seja apagada em silêncio.
     */
    public function test_a_policy_nao_declara_verbos_de_escrita_e_o_gate_nega_qualquer_membro(): void
    {
        $conta = Account::factory()->create();
        $admin = $this->membroDe($conta, 'admin');
        $operador = $this->membroDe($conta, 'operador');
        $leitor = $this->membroDe($conta, 'user');

        foreach (['admin', 'operador', 'user'] as $papel) {
            $membro = match ($papel) {
                'admin' => $admin,
                'operador' => $operador,
                default => $leitor,
            };

            $this->assertFalse(
                Gate::forUser($membro)->allows('create', SerproAuthorizationTerm::class),
                "O papel {$papel} não pode gravar o termo: a assinatura é do e-CNPJ do escritório, não de um Membro.",
            );
        }

        // E a ausência do verbo é estrutural, não uma coincidência de retorno.
        $policy = new SerproAuthorizationTermPolicy;

        foreach (['create', 'update', 'delete', 'view'] as $verbo) {
            $this->assertFalse(
                method_exists($policy, $verbo),
                "A policy não deve declarar `{$verbo}`: não há rota que o acione, e método sem rota é método que ninguém exercita.",
            );
        }
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

        $this->assertSame(
            1,
            SerproAuthorizationTerm::query()->where('account_id', $conta->getKey())->count(),
            'A primeira inserção tem de existir: é a segunda que o índice único tem de recusar.',
        );

        /*
         * A contagem vem **antes** do `expectException` de propósito. Na
         * versão anterior ela estava depois, e portanto nunca rodava — o
         * `expectException` transforma o resto do método em código inalcançável
         * para o PHPUnit, e uma asserção que nunca executa é uma asserção que
         * não prova nada, por mais escrita que esteja.
         */
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
    }

    /**
     * A largura declarada da coluna do estado é a do valor mais longo do enum.
     *
     * **Por que isto lê a migration e não o `information_schema`.** O
     * `Schema::getColumns()` deste framework não expõe o comprimento no SQLite —
     * devolve `varchar` e basta —, e o SQLite **não** recusa nem trunca um
     * `varchar` estreito. Um teste de ida e volta passaria aqui e reprovaria
     * só em produção, que é o pior lugar para o descobrir. O que tem de
     * concordar é a migration com o enum, e os dois são artefatos do
     * repositório: comparar os dois textos é a checagem que existe, e é a mesma
     * técnica que `test_o_cofre_extrai_o_documento_do_certificado_que_ja_abriu`
     * usa para a leitura do e-CNPJ.
     *
     * O caso existia porque o comentário da coluna dizia "treze posições" para
     * um valor que tem onze, e um número errado num comentário de coluna é a
     * forma mais barata de a próxima pessoa não alargar a coluna quando o enum
     * ganhar um estado: ela lê o comentário, acredita nele e não confere.
     */
    public function test_a_largura_da_coluna_do_estado_e_a_do_valor_mais_longo_do_enum(): void
    {
        $maisLongo = collect(SerproAuthorizationTermState::cases())
            ->map(fn (SerproAuthorizationTermState $caso): int => strlen($caso->value))
            ->max();

        $migration = file_get_contents(base_path('database/migrations/2026_09_28_113314_create_serpro_authorization_terms_table.php'));

        $this->assertIsString($migration);
        $this->assertSame(1, preg_match("/\\\$table->string\('state', (\d+)\)/", $migration, $achado), 'A migration declara a largura da coluna do estado.');
        $this->assertSame(
            $maisLongo,
            (int) $achado[1],
            'O enum ganhou um valor mais longo que a coluna: a migration tem de ser alargada, e o digest do formato recontado com a prova.',
        );
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

    /**
     * Um termo que a renovação consegue reenviar, escrito pelo caminho que a
     * emissão escreveria mas sem pagar uma assinatura por conta.
     *
     * A renovação não assina e não lê o e-CNPJ do escritório — é a propriedade
     * que o caso do `304` prova —, e por isso o que ela precisa é de uma linha
     * com documento cifrado legível, vigência no futuro e token anterior. Sem
     * assinatura, o caso da travessia inteira custa o que custam as 101 contas.
     */
    private function termoProntoParaRenovar(Account $conta): SerproAuthorizationTerm
    {
        return SerproAuthorizationTerm::forceCreate([
            'account_id' => $conta->getKey(),
            'author_document' => self::ESCRITORIO,
            'document_encrypted' => Crypt::encryptString('<termoDeAutorizacao><dados><vigencia data="20991231"/></dados></termoDeAutorizacao>'),
            'token_encrypted' => Crypt::encryptString(self::TOKEN),
            'document_expires_on' => now()->addDays(10)->startOfDay(),
            'token_expires_at' => now()->addDay(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'state_reason' => null,
            'signed_at' => now()->subDays(20),
            'last_submitted_at' => null,
        ]);
    }

    /**
     * A credencial passa a nomear outra empresa, e o documento continua válido.
     *
     * O número do contratante **não** muda: a conferência de identidade do
     * transporte compara o documento com o certificado, e mexer nele faria a
     * emissão falhar por um motivo que não é o deste caso. O que muda é a razão
     * social de onde o `destinatario` tira o nome.
     */
    private function rotacionarCredencial(): void
    {
        SerproConnection::query()->update([
            'certificate_subject' => 'CN='.self::OUTRA_PLATAFORMA.':'.self::PLATAFORMA,
        ]);
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
     *
     * **A validade é a de amanhã, e não uma data fixa.** O provedor promete o
     * token "até a meia-noite do dia seguinte, horário de Brasília", e é essa
     * a forma que o dublê tem de reproduzir: meia-noite de São Paulo, com um
     * segundo de diferença. Uma data escrita à mão seria de uma época que já
     * passou — e uma validade **vencida** deixa o termo `validado` desde a
     * correção que trata validade vencida como validade inutilizável, o que
     * faria este dublê de aceitação parar de aceitar. A data continua visível
     * no caso que a fixa, com o relógio congelado.
     */
    private function fakeApoiarAceito(): void
    {
        $this->respondeApoiar(200, [
            'status' => 200,
            'dados' => json_encode([
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => $this->validadeAceita(),
            ], JSON_THROW_ON_ERROR),
            'mensagens' => [['codigo' => '200', 'texto' => 'Sucesso na execução.']],
        ]);
    }

    /** A meia-noite de São Paulo de amanhã, no formato que o provedor publica. */
    private function validadeAceita(): string
    {
        return Carbon::now(SerproTermSigner::FUSO)->addDay()->startOfDay()->addSecond()->format('Y-m-d\TH:i:s');
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
