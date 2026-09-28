<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\SupportAccessLog;
use App\Models\User;
use App\Policies\AccountCertificatePolicy;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * O e-CNPJ do escritório, guardado cifrado no banco.
 *
 * O certificado do escritório é entregue uma vez e usado para sempre: quem
 * assina o termo de autorização é a plataforma, e nenhum Membro da conta
 * assina nada. Por isso os cenários que importam aqui não são de tela — são de
 * segredo (nem texto cifrado, nem senha, nem caminho saem), de forma (senha
 * errada não grava nada e não substitui o que já valia) e de isolamento (o
 * certificado de outra conta não existe para quem está numa conta).
 */
class SerproAccountCertificateTest extends TestCase
{
    use RefreshDatabase;

    /** O CNPJ que o certificado de descarte carrega no subject. */
    private const CNPJ = '12345678000195';

    /** O CNPJ do certificado de outra conta, usado no caso de isolamento. */
    private const CNPJ_ALHEIO = '27865757000102';

    private const SENHA = 'senha-de-teste';

    /** A frase do cofre para o certificado vencido, comparada byte a byte. */
    private const FRASE_VENCIDO = 'O e-CNPJ enviado está vencido: envie um certificado vigente para assinar o termo de autorização.';

    private const ROTA = '/api/serpro/account-certificate';

    /** O motivo pelo qual o container legado não saiu, quando ele não sai. */
    private ?string $legacyUnavailable = null;

    /** Quantos `select` em `account_certificates` a requisição sob medição fez. */
    private int $leituras = 0;

    /** Se a contagem de leituras está armada para a requisição atual. */
    private bool $contando = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum passo desta tela fala com o provedor: o e-CNPJ do escritório é
        // gravado aqui e lido por outro processo. Qualquer ida à rede seria
        // desvio de rota, não efeito colateral tolerável.
        Http::preventStrayRequests();

        // O disco do cofre de cliente existe para provar o oposto: o e-CNPJ do
        // escritório não passa por ele.
        Storage::fake('certificates');

        // A contagem de leituras da linha corrente é feita por `DB::listen`,
        // que é global: ela só vale para a requisição que a arma, e `descontar`
        // é o que faz o resto do teste não entrar na contagem.
        DB::listen(function (QueryExecuted $query): void {
            if ($this->contando && str_contains($query->sql, 'from "account_certificates"')) {
                $this->leituras++;
            }
        });
    }

    /**
     * Conta as leituras da linha corrente durante a chamada dada.
     *
     * @param  callable(): mixed  $acao
     */
    private function contando(callable $acao): mixed
    {
        $this->leituras = 0;
        $this->contando = true;

        try {
            return $acao();
        } finally {
            $this->contando = false;
        }
    }

    /**
     * Os middlewares que a rota carrega, pelo par método e URI.
     *
     * @return list<string>
     */
    private function routeMiddleware(string $method, string $uri): array
    {
        $rota = collect(Route::getRoutes())->first(
            fn ($candidata): bool => in_array($method, $candidata->methods(), true) && $candidata->uri() === $uri
        );

        $this->assertNotNull($rota, "Rota {$method} {$uri} não encontrada.");

        return $rota->gatherMiddleware();
    }

    /**
     * O upload do e-CNPJ é limitado, e o motivo é o mesmo do diagnóstico.
     *
     * **Cada sucesso custa uma submissão ao provedor.** O cofre agenda o
     * `IssueSerproTermJob` depois do commit, e o job faz um `submitTerm` de
     * verdade, com `tries = 1` e sem unicidade: não há o que impeça um
     * `admin`/`operador` de chamar a rota em laço e queimar a cota **do
     * escritório dele**, que é a cota de um parceiro do SERPRO e não uma
     * chamada interna. A rota vizinha de conectividade já é limitada por
     * exatamente esse argumento, e ela está a dez linhas de distância no mesmo
     * arquivo.
     *
     * O que o caso afirma é o middleware — o que a rota **tem** —, e não a
     * contagem: um teste que dependesse de seis uploads gastaria seis
     * certificados de descarte e mediria o `RateLimiter` do framework em vez
     * da decisão.
     */
    public function test_o_upload_do_ecnpj_e_limitado(): void
    {
        $this->assertContains(
            'throttle:6,1',
            $this->routeMiddleware('POST', 'api/serpro/account-certificate'),
            'Cada upload que o provedor aceita custa uma emissão de verdade, e a rota do diagnóstico é limitada pelo mesmo motivo.',
        );
    }

    /**
     * A policy do e-CNPJ não tem verbo que enderece a linha.
     *
     * **A invariante é a da docblock e ela não tinha trava.** O e-CNPJ é um
     * por conta e as três rotas não endereçam linha nenhuma: o upload
     * substitui o que estava valendo e a remoção apaga o que estava valendo. Um
     * `view(User, AccountCertificate $cert)` acrescentado "por simetria" com o
     * resto do produto cairia na armadilha que a própria docblock nomeia — a
     * autorização de uma linha de outra conta, respondendo pelo model em vez
     * de responder pela conta — e a policy irmã do termo, que tem a mesma
     * invariante, é verificada com um teste.
     *
     * A verificação é por **reflexão sobre os parâmetros**, e não por nome de
     * verbo: `delete` existe aqui sem model, e a forma que a regra proíbe é a
     * assinatura com o segundo parâmetro. Um método novo com model quebraria
     * este teste mesmo com um nome que ninguém esperaria.
     */
    public function test_a_policy_do_ecnpj_nao_declara_verbos_que_enderecem_a_linha(): void
    {
        $policy = new AccountCertificatePolicy;

        foreach ((new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC) as $metodo) {
            if ($metodo->getDeclaringClass()->getName() !== $policy::class) {
                continue;
            }

            $this->assertLessThanOrEqual(
                1,
                $metodo->getNumberOfParameters(),
                sprintf('`%s` recebe um segundo parâmetro: a rota do e-CNPJ não endereça linha, e um verbo com model autoriza a linha de qualquer conta.', $metodo->getName()),
            );
        }

        // E o que a policy faz de verdade continua valendo, para que a trava
        // acima não possa ser satisfeita apagando a policy inteira.
        $conta = Account::factory()->create();

        resolve(CurrentTenant::class)->accountId = $conta->getKey();

        $this->assertTrue(Gate::forUser($this->membroDe($conta, 'admin'))->allows('create', AccountCertificate::class));
        $this->assertTrue(Gate::forUser($this->membroDe($conta, 'operador'))->allows('delete', AccountCertificate::class));
        $this->assertFalse(Gate::forUser($this->membroDe($conta, 'user'))->allows('create', AccountCertificate::class));
    }

    public function test_admin_e_operador_enviam_o_ecnpj_do_escritorio(): void
    {
        $conta = Account::factory()->create();
        foreach (['admin', 'operador'] as $papel) {
            ['bytes' => $bytes, 'file' => $arquivo] = $this->pfx('escritorio.p12');

            $this->actingAs($this->membroDe($conta, $papel), 'sanctum')
                ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
                ->assertOk()
                ->assertJsonPath('data.document', self::CNPJ)
                ->assertJsonPath('data.original_filename', 'escritorio.p12');
        }

        $this->assertSame(2, AccountCertificate::query()->count());

        // O que fica gravado é o **cifrado**, e o cifrado ainda abre: sem esta
        // segunda metade, "gravou algo" e "guardou o e-CNPJ" seriam a mesma
        // afirmação.
        $linha = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($linha);
        $this->assertNotSame($bytes, $linha->getRawOriginal('certificate_encrypted'));
        $this->assertNotSame(self::SENHA, $linha->getRawOriginal('password_encrypted'));
        $this->assertSame(hash('sha256', $bytes), $linha->sha256);
        $this->assertSame(self::CNPJ, $linha->document);
        $this->assertSame(self::SENHA, $linha->certificatePassword());
        $this->assertSame($bytes, $linha->certificateBytes());
        $this->assertStringContainsString('Escritorio Contabil', (string) $linha->subject);
        $this->assertNotNull($linha->serial_number);
        $this->assertTrue($linha->valid_until->isFuture());
        $this->assertNull($linha->replaced_at);
        $this->assertNull($linha->removed_at);
    }

    public function test_a_resposta_nao_traz_ciphertext_nem_senha_e_a_linha_inteira_tambem_nao(): void
    {
        $conta = Account::factory()->create();
        // Uma senha com espaço: a que o `openssl_pkcs12_export` gravou é a que o
        // `openssl_pkcs12_read` precisa abrir, e uma senha truncada em aspas ou
        // em `-passout` passaria no teste e quebraria na assinatura.
        $senha = 'senha do escritorio';
        ['bytes' => $bytes, 'file' => $arquivo] = $this->pfx('escritorio.p12', $senha);

        $resposta = $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => $senha], $this->jsonHeaders())
            ->assertOk();

        // A lista é fechada: nenhuma chave a mais (o segredo) e nenhuma a menos
        // (o que a tela precisa para reconhecer o certificado guardado).
        $this->assertSame([
            'document',
            'id',
            'original_filename',
            'serial_number',
            'subject',
            'uploaded_at',
            'valid_from',
            'valid_until',
        ], $this->chavesOrdenadas($resposta->json('data')));

        foreach (['certificate_encrypted', 'password_encrypted', 'password', 'path', 'storage_path'] as $proibida) {
            $resposta->assertJsonMissingPath('data.'.$proibida);
        }

        $corpo = (string) $resposta->getContent();
        $linha = AccountCertificate::currentFor($conta->getKey());

        $this->assertStringNotContainsString($senha, $corpo);
        $this->assertStringNotContainsString(base64_encode($bytes), $corpo);
        $this->assertStringNotContainsString(bin2hex($bytes), $corpo);
        $this->assertStringNotContainsString((string) $linha?->getRawOriginal('certificate_encrypted'), $corpo);
        $this->assertStringNotContainsString((string) $linha?->getRawOriginal('password_encrypted'), $corpo);

        // A segunda rede: serializar a linha inteira — que é o que faria um
        // `return $certificate` em um controller — não devolve segredo.
        $this->assertArrayNotHasKey('certificate_encrypted', $linha->toArray());
        $this->assertArrayNotHasKey('password_encrypted', $linha->toArray());
        $this->assertStringNotContainsString($senha, (string) $linha->toJson());
    }

    public function test_documento_vem_do_certificado_e_nao_da_requisicao(): void
    {
        $conta = Account::factory()->create();
        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        // A `Account` não tem coluna de CNPJ: não há contra o que comparar, e o
        // documento do contratante precisa ser o que o certificado carrega. Um
        // campo no corpo da requisição é recusado, e não ignorado em silêncio.
        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, [
                'certificate' => $arquivo,
                'password' => self::SENHA,
                'document' => self::CNPJ_ALHEIO,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->assertDatabaseCount('account_certificates', 0);

        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk()
            ->assertJsonPath('data.document', self::CNPJ);

        $this->assertSame(self::CNPJ, AccountCertificate::currentFor($conta->getKey())?->document);
    }

    public function test_senha_errada_responde_422_sem_gravar_nem_substituir_o_que_esta_gravado(): void
    {
        $conta = Account::factory()->create();
        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => 'senha-errada'], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('account_certificates', 0);
        $this->assertNull(AccountCertificate::currentFor($conta->getKey()));
        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')->getJson(self::ROTA)->assertNotFound();

        // Com um certificado já gravado, a senha errada tem de deixar o
        // certificado que estava valendo exatamente como estava: um upload
        // recusado que troca o segredo do escritório por nada é pior do que a
        // recusa.
        ['file' => $primeiro] = $this->pfx('primeiro.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $primeiro, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $vigente = AccountCertificate::currentFor($conta->getKey());
        $cifradoAntes = $vigente?->getRawOriginal('certificate_encrypted');
        $senhaAntes = $vigente?->getRawOriginal('password_encrypted');

        ['file' => $segundo] = $this->pfx('segundo.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $segundo, 'password' => 'senha-errada'], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        // Nenhuma linha nova: a recusa acontece antes de qualquer escrita, e a
        // linha que valia continua valendo com o mesmo texto cifrado.
        $this->assertSame(1, AccountCertificate::query()->count());
        $this->assertSame($cifradoAntes, $vigente?->refresh()->getRawOriginal('certificate_encrypted'));
        $this->assertSame($senhaAntes, $vigente?->refresh()->getRawOriginal('password_encrypted'));
        $this->assertNull($vigente?->refresh()->replaced_at);
        $this->assertSame('primeiro.p12', AccountCertificate::currentFor($conta->getKey())?->original_filename);
    }

    /**
     * A recusa do certificado vencido é do cofre, e não consequência de
     * passageia de um serviço que ele consome.
     *
     * `CertificatePkcs12::inspect()` devolve `valid_until` e não recusa por
     * vigência — certo para o cofre de cliente, que guarda histórico. Sem o
     * guard do cofre, a plataforma assinaria o termo de autorização com um
     * e-CNPJ vencido, e nenhum teste da leitura compartilhada impede isso.
     */
    public function test_certificado_vencido_e_recusado_com_mensagem_proria(): void
    {
        $conta = Account::factory()->create();
        ['file' => $arquivo] = $this->pfx('vencido.p12', self::SENHA, 0);

        $recusa = $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        // A forma é a da senha errada — erro de validação, nada gravado —, e a
        // frase é do cofre: quem lê o erro precisa saber que o problema é a
        // vigência do arquivo, e não o que ele digitou.
        $this->assertSame(self::FRASE_VENCIDO, $recusa->json('errors.certificate.0'));
        $this->assertDatabaseCount('account_certificates', 0);
        $this->assertSame([], Storage::disk('certificates')->allFiles());

        // E a recusa não é a de senha errada: as duas saem do mesmo
        // `openssl_pkcs12_read`, que não diz qual das duas coisas houve, e o
        // que o operador faz depois — reenviar o certificado ou digitar a senha
        // de novo — não é o mesmo nos dois casos.
        ['file' => $arquivo] = $this->pfx('valido.p12');

        $senhaErrada = $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => 'senha-errada'], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertNotSame($senhaErrada->json('errors.password.0'), $recusa->json('errors.certificate.0'));
    }

    /**
     * Um container que este OpenSSL não abre por ser cifrado com um algoritmo
     * legado — RC4, no fixture — produz o mesmo sintoma de senha errada, e a
     * leitura compartilhada não promete RC2 sem ter byte de RC2.
     *
     * Aí está a decisão do cofre do Account: a recusa nomeia as três causas que
     * ele não consegue distinguir, em vez de jurar que a senha falhou.
     */
    public function test_container_legado_de_outro_algoritmo_nao_e_acusado_de_senha_errada(): void
    {
        $bytes = $this->containerLegado(['-certpbe', 'rc4', '-keypbe', 'rc4'], 'rc4');

        if ($bytes === null) {
            $this->markTestSkipped(sprintf(
                'O container legado de RC4 não foi gerado: %s.',
                $this->legacyUnavailable ?? 'motivo não determinado',
            ));
        }

        $conta = Account::factory()->create();

        $recusa = $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, [
                'certificate' => UploadedFile::fake()->createWithContent('legado.pfx', $bytes),
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $mensagem = (string) $recusa->json('errors.password.0');

        // A frase que o cofre de cliente devolve ("…com a senha informada") só
        // é verdadeira quando o arquivo é lido de outra forma; o escritório que
        // recebe isso digita a senha certa de novo, falha de novo e abre um
        // chamado. A do escritório nomeia a criptografia legada.
        $this->assertStringContainsString('criptografia legada', $mensagem);
        $this->assertStringNotContainsString('com a senha informada', $mensagem);
        $this->assertStringNotContainsString(self::SENHA, $mensagem);
        $this->assertStringNotContainsString($bytes, $mensagem);

        $this->assertDatabaseCount('account_certificates', 0);
    }

    /**
     * O container com RC2 é o único caso em que a leitura compartilhada nomeia o
     * algoritmo, e a frase dela continua inteira.
     *
     * O que o cofre do escritório **não** faz é acrescentar o nome da conta: um
     * nome de escritório em um 422 é a resposta a "de quem é este erro", e
     * nomear o escritório é a operação que o `CertificatePkcs12` reserva ao
     * cofre de cliente, que precisa saber de qual cliente é o arquivo para
     * refazer o export certo.
     */
    public function test_container_rc2_legado_diz_rc2_sem_nomear_o_escritorio(): void
    {
        $bytes = $this->containerLegado([], 'rc2');

        if ($bytes === null) {
            $this->markTestSkipped(sprintf(
                'O container legado com RC2 não foi gerado: %s.',
                $this->legacyUnavailable ?? 'motivo não determinado',
            ));
        }

        $conta = Account::factory()->create(['name' => 'Escritorio Alheio Consultoria']);

        $recusa = $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, [
                'certificate' => UploadedFile::fake()->createWithContent('legado.pfx', $bytes),
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        $mensagem = (string) $recusa->json('errors.certificate.0');

        $this->assertStringContainsString('criptografia legada RC2', $mensagem);
        $this->assertStringNotContainsString('Alheio', $mensagem);
        $this->assertStringNotContainsString(self::SENHA, $mensagem);

        $this->assertDatabaseCount('account_certificates', 0);
    }

    public function test_substituicao_marca_a_linha_anterior_e_apaga_somente_o_ciphertext(): void
    {
        $conta = Account::factory()->create();
        $operador = $this->membroDe($conta, 'operador');

        ['bytes' => $bytesAntigos, 'file' => $primeiro] = $this->pfx('primeiro.p12', self::SENHA, 365, self::CNPJ_ALHEIO);

        $this->actingAs($operador, 'sanctum')
            ->post(self::ROTA, ['certificate' => $primeiro, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $antiga = AccountCertificate::query()->sole();

        ['bytes' => $bytesNovos, 'file' => $segundo] = $this->pfx('segundo.p12');

        $this->actingAs($operador, 'sanctum')
            ->post(self::ROTA, ['certificate' => $segundo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $historico = $antiga->refresh();
        $atual = AccountCertificate::currentFor($conta->getKey());

        // Uma linha por gravação, e só uma corrente.
        $this->assertSame(2, AccountCertificate::query()->count());
        $this->assertSame(1, AccountCertificate::query()
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->count());

        // O conteúdo cifrado anterior é apagado — é o que apaga o segredo de
        // verdade — e a linha sobrevive com os metadados não secretos, que é o
        // que faz dela histórico em vez de lixo. O `document` fica: é a
        // identidade que o escritório tinha na época.
        $this->assertNotNull($historico->replaced_at);
        $this->assertNull($historico->removed_at);
        $this->assertNull($historico->certificate_encrypted);
        $this->assertNull($historico->password_encrypted);
        $this->assertSame(self::CNPJ_ALHEIO, $historico->document);
        $this->assertSame('primeiro.p12', $historico->original_filename);
        $this->assertSame(hash('sha256', $bytesAntigos), $historico->sha256);
        $this->assertNotNull($historico->subject);
        $this->assertNotNull($historico->serial_number);
        $this->assertNotNull($historico->valid_from);
        $this->assertTrue($historico->valid_until->isFuture());

        // A nova é a corrente, com os dois marcadores vazios.
        $this->assertNotNull($atual);
        $this->assertNotSame($antiga->getKey(), $atual->getKey());
        $this->assertNull($atual->replaced_at);
        $this->assertNull($atual->removed_at);
        $this->assertSame(self::CNPJ, $atual->document);
        $this->assertSame(hash('sha256', $bytesNovos), $atual->sha256);
        $this->assertNotSame($bytesNovos, $atual->getRawOriginal('certificate_encrypted'));
        $this->assertSame($bytesNovos, $atual->certificateBytes());
    }

    public function test_remocao_apaga_o_conteudo_e_mantem_o_historico_legivel(): void
    {
        $conta = Account::factory()->create();
        ['bytes' => $bytes, 'file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $id = AccountCertificate::query()->sole()->getKey();

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->deleteJson(self::ROTA)
            ->assertNoContent();

        $historico = AccountCertificate::query()->findOrFail($id);

        // O conteúdo vai embora e o histórico fica: a linha existe, sem
        // segredo, e é dela que se responde "que certificado o escritório teve
        // em março".
        $this->assertNotNull($historico->removed_at);
        $this->assertNull($historico->replaced_at);
        $this->assertNull($historico->certificate_encrypted);
        $this->assertNull($historico->password_encrypted);
        $this->assertSame(self::CNPJ, $historico->document);
        $this->assertSame('escritorio.p12', $historico->original_filename);
        $this->assertSame(hash('sha256', $bytes), $historico->sha256);
        $this->assertNotNull($historico->valid_until);

        // E a linha sem bytes não finge que tem: quem pedir os bytes recebe uma
        // falha, e não string vazia.
        $this->expectException(RuntimeException::class);
        $historico->certificateBytes();
    }

    public function test_removido_e_trocado_deixam_de_ser_o_certificado_corrente(): void
    {
        $conta = Account::factory()->create();
        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        // As duas linhas fora de vigência passam pelos estados da factory, e não
        // por `['removed_at' => now()]` sobre a linha corrente: uma linha fora
        // de vigência **não tem conteúdo cifrado**, porque é o cofre que o apaga
        // ao marcar. Montar o fixture na mão produzia uma linha que nunca existiu
        // — com o segredo presente e o marcador posto — e era a forma que o
        // próximo teste ia copiar.
        $removida = AccountCertificate::factory()->removed()->create([
            'account_id' => $conta->getKey(),
            'document' => self::CNPJ_ALHEIO,
        ]);
        $trocada = AccountCertificate::factory()->replaced()->create([
            'account_id' => $conta->getKey(),
            'document' => self::CNPJ_ALHEIO,
        ]);

        $this->assertNull($removida->certificate_encrypted);
        $this->assertNull($removida->password_encrypted);
        $this->assertNull($trocada->certificate_encrypted);
        $this->assertNull($trocada->password_encrypted);

        // `latest('id')` escolhe a corrente, e não a última linha gravada: as
        // duas que estão fora de vigência precisam ser invisíveis para quem
        // pergunta pelo certificado do escritório.
        $atual = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($atual);
        $this->assertNotSame($removida->getKey(), $atual->getKey());
        $this->assertNotSame($trocada->getKey(), $atual->getKey());
        $this->assertNull($atual->removed_at);
        $this->assertNull($atual->replaced_at);

        // Sem corrente nenhuma, a leitura da tela é `404`.
        AccountCertificate::query()->whereNull('removed_at')->whereNull('replaced_at')->delete();

        $this->assertNull(AccountCertificate::currentFor($conta->getKey()));
        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')->getJson(self::ROTA)->assertNotFound();
    }

    /**
     * A razão de o e-CNPJ do escritório estar cifrado no banco e não em disco: o
     * sistema de arquivos do container do Laravel é efêmero em produção, e um
     * certificado em arquivo sumiria a cada recriação — o escritório voltaria a
     * ter que autorizar depois de todo deploy.
     *
     * Este é o teste dessa razão: uma **instância nova** do modelo, sem nenhum
     * arquivo preparado, abre o PFX gravado com a senha guardada ao lado.
     */
    public function test_o_arquivo_sobrevive_a_uma_instancia_nova_do_model_e_nao_ha_coluna_de_caminho(): void
    {
        $conta = Account::factory()->create();
        ['bytes' => $bytes, 'file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $id = AccountCertificate::currentFor($conta->getKey())?->getKey();

        // Instância nova, lida do banco, sem o modelo em memória e sem disco.
        $linha = AccountCertificate::query()->findOrFail($id);
        $abre = [];
        $bytesLidos = $linha->certificateBytes();

        $this->assertSame($bytes, $bytesLidos);
        $this->assertTrue(openssl_pkcs12_read($bytesLidos, $abre, (string) $linha->certificatePassword()));
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) ($abre['cert'] ?? ''));
        $this->assertStringContainsString('PRIVATE KEY', (string) ($abre['pkey'] ?? ''));

        // Nenhum disco foi tocado: `certificates` é o mesmo disco que o cofre
        // de cliente usa, e a leitura do e-CNPJ não escreve nele.
        $this->assertSame([], Storage::disk('certificates')->allFiles());

        // E a tabela não tem onde guardar caminho — a decisão de projeto, e não
        // um detalhe de implementação. Um `storage_path` aqui reabriria a porta
        // que o design fechou.
        $colunas = Schema::getColumnListing('account_certificates');

        foreach (['path', 'storage_path', 'certificate_path', 'file_path', 'disk'] as $proibida) {
            $this->assertNotContains($proibida, $colunas);
        }

        $this->assertContains('certificate_encrypted', $colunas);
        $this->assertContains('password_encrypted', $colunas);
        $this->assertContains('document', $colunas);
    }

    public function test_certificado_de_outra_conta_nao_e_encontrado(): void
    {
        $minha = Account::factory()->create();
        $outra = Account::factory()->create();

        $alheia = AccountCertificate::factory()->create([
            'account_id' => $outra->getKey(),
            'document' => self::CNPJ_ALHEIO,
            'original_filename' => 'alheio.p12',
        ]);

        // A conta corrente não tem certificado nenhum: a leitura é `404`, e o
        // certificado da outra conta não aparece nem como `configured`.
        $this->actingAs($this->membroDe($minha, 'admin'), 'sanctum')
            ->getJson(self::ROTA)
            ->assertNotFound();

        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($minha, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk()
            ->assertJsonPath('data.document', self::CNPJ);

        // Com as duas contas tendo certificado, a leitura devolve o da conta
        // corrente e o nome do arquivo alheio não aparece em lugar nenhum: uma
        // busca sem `account_id` devolveria a linha de maior id, que é a alheia.
        $resposta = $this->actingAs($this->membroDe($minha, 'admin'), 'sanctum')
            ->getJson(self::ROTA)
            ->assertOk()
            ->assertJsonPath('data.document', self::CNPJ)
            ->assertJsonPath('data.original_filename', 'escritorio.p12');

        $this->assertStringNotContainsString('alheio', (string) $resposta->getContent());
        $this->assertNotSame($alheia->getKey(), $resposta->json('data.id'));

        // A linha alheia segue intacta: o `404` é de quem pergunta, não uma
        // remoção por engano.
        $this->assertDatabaseHas('account_certificates', [
            'id' => $alheia->getKey(),
            'account_id' => $outra->getKey(),
            'document' => self::CNPJ_ALHEIO,
        ]);
    }

    /**
     * O `account_id` de `currentFor()` é explícito, e o escopo global não pode
     * ser a garantia disso.
     *
     * A rota já impede a fuga de tenant por outros caminhos — o middleware
     * `tenant` e o escopo condicional de `BelongsToAccount` —, e por isso o
     * `404` do teste de isolamento continuaria passando **se o filtro
     * explícito sumisse**. A diferença entre os dois aparece fora da requisição,
     * que é onde quem assina o termo vive: o console, o seed e o `queue:work`,
     * onde não há `CurrentTenant` e o escopo global não filtra nada.
     *
     * São dois comportamentos, e o teste fixa os dois porque eles não são o
     * mesmo: com o singleton apontando para outra conta, as duas condições se
     * cancelam e o resultado é `null` — a falha de um tenant é **recusar**, e
     * recusar é o que impede que o valor de um job anterior vaze certificado de
     * outra conta. Sem singleton nenhum, o filtro explícito é a única filtragem
     * que existe, e ela precisa devolver a conta pedida.
     */
    public function test_current_for_filtra_pelo_account_id_mesmo_fora_do_escopo_do_tenant(): void
    {
        $minha = Account::factory()->create();
        $outra = Account::factory()->create();

        $meu = AccountCertificate::factory()->create([
            'account_id' => $minha->getKey(),
            'document' => self::CNPJ,
            'original_filename' => 'meu.p12',
        ]);
        $alheio = AccountCertificate::factory()->create([
            'account_id' => $outra->getKey(),
            'document' => self::CNPJ_ALHEIO,
            'original_filename' => 'alheio.p12',
        ]);

        // Singleton apontando para a conta errada: o estado em que um
        // `queue:work` fica depois de atender outra conta. Recusa, não vaza.
        resolve(CurrentTenant::class)->accountId = $outra->getKey();

        $this->assertNull(AccountCertificate::currentFor($minha->getKey()));
        $this->assertSame($alheio->getKey(), AccountCertificate::currentFor($outra->getKey())?->getKey());

        // E sem tenant nenhum, que é o console e o seed: sem escopo global
        // filtrando, o `where('account_id', …)` explícito é tudo o que há, e é
        // ele que precisa devolver a conta pedida — inclusive quando há
        // certificado em mais de uma conta.
        resolve(CurrentTenant::class)->accountId = null;

        $this->assertSame($meu->getKey(), AccountCertificate::currentFor($minha->getKey())?->getKey());
        $this->assertSame($alheio->getKey(), AccountCertificate::currentFor($outra->getKey())?->getKey());
    }

    public function test_membro_user_le_e_nao_escreve(): void
    {
        $conta = Account::factory()->create();
        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $leitor = $this->membroDe($conta, 'user');

        // Ler não é operar a conta: qualquer Membro vê qual e-CNPJ está gravado.
        $this->actingAs($leitor, 'sanctum')
            ->getJson(self::ROTA)
            ->assertOk()
            ->assertJsonPath('data.document', self::CNPJ);

        // Gravar é do `admin` e do `operador`. O papel `user` não aparece em
        // nenhuma policy do produto e é somente leitura na prática.
        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($leitor, 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertForbidden();

        $this->actingAs($leitor, 'sanctum')->deleteJson(self::ROTA)->assertForbidden();

        $linha = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($linha);
        $this->assertSame('escritorio.p12', $linha->original_filename);
        $this->assertNull($linha->removed_at);
        $this->assertNull($linha->replaced_at);
        $this->assertSame(1, AccountCertificate::query()->count());
    }

    public function test_arquivo_que_nao_e_pfx_e_arquivo_acima_de_2_mib_sao_recusados(): void
    {
        $conta = Account::factory()->create();
        $operador = $this->membroDe($conta, 'operador');

        $this->actingAs($operador, 'sanctum')
            ->post(self::ROTA, [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.txt', 'isto nao e um pkcs12'),
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        // A escada de tamanho: `max:2048` são 2 MiB em kilobytes, e a recusa
        // acontece antes do cofre — o arquivo nem é lido.
        $this->actingAs($operador, 'sanctum')
            ->post(self::ROTA, [
                'certificate' => UploadedFile::fake()->createWithContent('grande.pfx', str_repeat('a', 2 * 1024 * 1024 + 1)),
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        // A senha é obrigatória no upload: um e-CNPJ sem senha não é o
        // formulário desta tela, e gravá-lo deixaria um certificado que ninguém
        // consegue abrir.
        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($operador, 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('account_certificates', 0);
    }

    /**
     * O nome do arquivo é o que o cliente digitou, e o cliente não tem um teto.
     *
     * A coluna é `varchar(255)` e o Postgres **não** trunca: um nome de 256
     * caracteres viraria `500` de banco de dados no meio de um upload válido —
     * um `500` que não é do operador corrigir reenviando, porque o arquivo está
     * certo. O `ClientCertificateVault` tem a mesma linha e o mesmo problema, e
     * o nome do arquivo é limitado aqui porque este é o segundo lugar onde ele
     * é gravado; o primeiro é dos clientes e fica para quando aquele cofre for
     * tocado.
     *
     * O corte é no limite da coluna e não em um mais apertado, e ele é visível:
     * um nome cortado muda na resposta, e mudar é melhor do que recusar o
     * e-CNPJ por causa do nome com que ele chegou.
     */
    public function test_nome_de_arquivo_acima_do_limite_da_coluna_e_guardado_sem_estourar(): void
    {
        $conta = Account::factory()->create();

        // 300 + ".p12": 304 posições, quase cinquenta acima do `varchar(255)`.
        $nomeLongo = str_repeat('a', 300).'.p12';

        $this->assertGreaterThan(255, mb_strlen($nomeLongo), 'O nome do caso tem de estourar o varchar(255).');

        ['file' => $arquivo] = $this->pfx($nomeLongo);

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $gravado = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($gravado);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $gravado->original_filename));
        $this->assertSame($gravado->original_filename, $gravado->fresh()->original_filename);

        // A linha é a mesma linha de sempre: o nome foi aparado, o certificado
        // não foi recusado nem trocado.
        $this->assertSame(1, AccountCertificate::query()->count());
        $this->assertNotNull($gravado->certificate_encrypted);
        $this->assertTrue($gravado->valid_until->isFuture());

        // E o nome que volta é o que foi gravado, não o que foi pedido.
        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->getJson(self::ROTA)
            ->assertOk()
            ->assertJsonPath('data.original_filename', $gravado->original_filename);
    }

    /**
     * O `subject` é uma DN que **a AC escolhe**, e ela é do tamanho que ela
     * quiser.
     *
     * **O caso reproduz a DN de um e-CNPJ de verdade, e ela é grande.** Um
     * certificado ICP-Brasil antigo traz meia dúzia de RDNs — país, O, a AC
     * que emitiu, o título do certificado, cidade, estado, o CN com a razão
     * social e o documento, e-mail — e o `X509_NAME_oneline` concatena todos:
     * o que a revisão mediu num e-CNPJ G5 real foi **274 caracteres**, e uma
     * razão social de sessenta caracteres leva a forma longa a mais de duzentos
     * e noventa. A coluna é `varchar(255)` e o Postgres **recusa** um valor
     * maior: um `500` no meio de um upload cujo arquivo está totalmente
     * correto, para um escritório que **não pode encurtar a própria DN** —
     * ele não escolhe o certificado que a AC emitiu. Diferente do nome do
     * arquivo, para o qual existe o nome completo como conserto, aqui o que
     * some é a folha de rosto do certificado e o conserto é o mesmo corte.
     *
     * A suíte não veria isso: o SQLite aceita `varchar` estourado em silêncio e
     * o `subject` do certificado de descarte tem 55 caracteres.
     */
    public function test_uma_dn_de_aceite_maior_que_a_coluna_e_aparada_sem_recusar_o_ecnpj(): void
    {
        $conta = Account::factory()->create();

        ['file' => $arquivo, 'bytes' => $bytes] = $this->pfx('escritorio.p12', subject: $this->dnLonga());

        $lida = [];
        $this->assertTrue(openssl_pkcs12_read($bytes, $lida, self::SENHA), 'O PFX de descarte tem de abrir.');

        $nome = (string) openssl_x509_parse($lida['cert'])['name'];

        $this->assertGreaterThan(255, mb_strlen($nome), 'A DN do caso tem de estourar o varchar(255).');

        // O e-CNPJ entra. Recusá-lo por causa do nome do sujeito seria trocar um
        // `500` por um `422` que o escritório não tem como corrigir.
        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $gravado = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($gravado);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $gravado->subject));
        $this->assertTrue(mb_check_encoding((string) $gravado->subject, 'UTF-8'), 'O corte tem de contar caractere, e não byte.');
        $this->assertSame($gravado->subject, $gravado->fresh()->subject);

        // A identidade continua extraída do certificado inteiro, e não do
        // `subject` gravado: o corte é de exibição, e um corte que levasse o CN
        // junto derrubaria a emissão do termo.
        $this->assertSame(self::CNPJ, $gravado->document);
        $this->assertNotNull($gravado->certificate_encrypted);
        $this->assertSame(1, AccountCertificate::query()->count());
    }

    /**
     * Uma DN de e-CNPJ do padrão ICP-Brasil, com os RDNs que a AC emite.
     *
     * @return array<string, string>
     */
    private function dnLonga(): array
    {
        return [
            'C' => 'BR',
            'O' => 'ICP-Brasil',
            'OU' => 'Autoridade Certificadora Raiz da ICP-Brasil, G5 - Cassia Digital',
            'title' => 'Certificado Digital para Pessoa Juridica - e-CNPJ A1',
            'L' => 'Sao Paulo',
            'ST' => 'Sao Paulo',
            'CN' => 'TESTE CONTABIL EIRELI - ME:'.self::CNPJ,
            'emailAddress' => 'contato@testecontabil.com.br',
            'description' => 'Certificado emitido para pessoa juridica',
        ];
    }

    /**
     * O corte conta **caractere**, e o `varchar(255)` do Postgres também conta
     * caractere — que é o que faz o `mb_substr` ser a operação certa aqui.
     *
     * O que um corte em bytes faria com um nome acentuado é pior do que estourar
     * a coluna: `substr($nome, 0, 255)` de um nome de caracteres de dois bytes
     * cai no meio de um deles, e o que vai para a coluna é um texto com byte
     * inválido — que o Postgres aceita em `varchar` mas que a tela mostra com
     * caractere de replacement, e que a comparação do nome deixa de bater.
     *
     * Este caso é o que separa os dois cortes, e por isso o nome é de
     * multibyte de propósito: com um nome de ASCII, `substr` e `mb_substr` dão
     * o mesmo resultado e o defeito não aparece.
     */
    public function test_nome_de_arquivo_com_acento_e_cortado_sem_byte_invalido(): void
    {
        $conta = Account::factory()->create();

        // 200 "ã" (2 bytes cada) + ".p12": 204 caracteres e 404 bytes. O nome
        // cabe em `varchar(255)` por caractere e estouraria por byte — que é
        // exatamente a confusão que o corte por caractere desfaz.
        $nome = str_repeat('ã', 200).'.p12';

        $this->assertLessThanOrEqual(255, mb_strlen($nome), 'O nome cabe na coluna por caractere.');
        $this->assertGreaterThan(255, strlen($nome), 'E não cabe por byte, que é o que o corte em bytes usaria.');

        ['file' => $arquivo] = $this->pfx($nome);

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $gravado = (string) AccountCertificate::currentFor($conta->getKey())?->original_filename;

        $this->assertTrue(
            mb_check_encoding($gravado, 'UTF-8'),
            'O nome gravado tem byte inválido, o que é o que o corte em byte produziria.',
        );
        $this->assertLessThanOrEqual(255, mb_strlen($gravado));
        $this->assertSame($gravado, mb_substr($nome, 0, 255));
    }

    /**
     * A auditoria da remoção tem de nomear **a linha que foi removida**, e não
     * uma leitura da linha corrente feita fora da transação.
     *
     * Com as duas leituras separadas, o controller lê a corrente, o cofre abre a
     * transação e lê a corrente de novo — e nesse meio tempo um upload concorrente
     * pode ter trocado a linha. A auditoria passaria a registrar o `document` de
     * um certificado que já não é o do escritório, que é a única coisa que a
     * auditoria existe para dizer.
     *
     * O sintoma é observável sem concorrência: o cofre devolve a linha que
     * removeu, e é essa linha — com o `document` e o `id` dela — que entra no
     * registro.
     */
    public function test_a_auditoria_da_remocao_nomeia_a_linha_que_o_cofre_removeu(): void
    {
        $alvo = Account::factory()->create();

        $super = User::factory()->create(['is_super_admin' => true]);
        $casa = Account::factory()->create();
        AccountUser::create(['account_id' => $casa->getKey(), 'user_id' => $super->getKey(), 'role' => 'admin']);
        $super->forceFill(['current_account_id' => $alvo->getKey()])->save();
        $super = $super->refresh();

        // A conta tem uma linha anterior fora de vigência, de um e-CNPJ que já
        // foi trocado, e a corrente por último. O que se fixa aqui é que o
        // `document` e o `id` registrados são os da linha **removida** — a que
        // estava valendo —, e não os de qualquer outra linha da conta.
        $historicoAntigo = AccountCertificate::factory()->replaced()->create([
            'account_id' => $alvo->getKey(),
            'document' => '11222333000181',
        ]);

        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($super, 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $corrente = AccountCertificate::currentFor($alvo->getKey());

        $this->assertNotNull($corrente);
        $this->assertSame(self::CNPJ, $corrente->document);
        $this->assertNotSame($historicoAntigo->getKey(), $corrente->getKey());

        // A remoção, com a contagem de leituras armada: o que se quer ver é que a
        // linha corrente é lida **uma vez**. O controller lia a corrente para
        // montar a auditoria e o cofre a lia de novo dentro da transação — duas
        // queries para uma linha, e duas chances de verem linhas diferentes.
        // O cofre devolve a linha que removeu, e é dela que a auditoria sai.
        $this->contando(function () use ($super): void {
            $this->actingAs($super, 'sanctum')->deleteJson(self::ROTA)->assertNoContent();
        });

        $this->assertSame(1, $this->leituras, 'A linha corrente foi lida mais de uma vez durante a remoção.');

        $log = SupportAccessLog::query()
            ->where('action', 'delete')
            ->sole();

        $this->assertSame($corrente->getKey(), $log->metadata['resource_id'] ?? null);
        $this->assertSame(self::CNPJ, $log->metadata['document'] ?? null);

        // Uma linha removida, e só a que estava valendo.
        $this->assertNotNull($corrente->refresh()->removed_at);
        $this->assertNotNull($historicoAntigo->refresh()->replaced_at);
        $this->assertNull($historicoAntigo->refresh()->removed_at);
        $this->assertNull(AccountCertificate::currentFor($alvo->getKey()));
    }

    /**
     * Tudo o que a linha grava vem do mesmo arquivo, e a fonte de cada campo
     * importa porque a convergência das duas leituras aconteceu.
     *
     * `CertificatePkcs12::inspect()` abre o PKCS#12 e devolve metadados **e** o
     * certificado, e o `SerproCertificateIdentity` extrai o documento desse
     * certificado já aberto — a segunda leitura dos mesmos bytes saiu. O
     * caminho agora é um só, e o que este caso fixa é que ele continua
     * descrevendo **um** arquivo: o `sha256` dos bytes enviados, o `subject` e o
     * número de série que o certificado declara, a validade, e o CNPJ de dentro
     * dele.
     *
     * A divergência que este teste caça é a forma silenciosa do defeito: se o
     * `document` viesse de um certificado e o `sha256` de outro, a linha passaria
     * a descrever um e-CNPJ que ninguém assinou, e nada na tela mostraria isso.
     */
    public function test_a_linha_gravada_descreve_o_mesmo_certificado_que_o_cofre_abriu(): void
    {
        $conta = Account::factory()->create();
        ['bytes' => $bytes, 'file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $linha = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($linha);

        // O `sha256` é dos bytes que foram enviados, e não de uma releitura: é o
        // que o `CertificatePkcs12` calcula e é o que o `certificateBytes()` de
        // uma instância nova devolve.
        $this->assertSame(hash('sha256', $bytes), $linha->sha256);
        $this->assertSame($bytes, $linha->certificateBytes());

        // O que o cofre gravou bate com o que o próprio certificado diz, lido
        // direto do X.509 dos mesmos bytes.
        $aberto = [];
        $this->assertTrue(openssl_pkcs12_read($bytes, $aberto, self::SENHA));
        $metadados = openssl_x509_parse((string) $aberto['cert']);

        $this->assertIsArray($metadados);
        $this->assertSame($metadados['name'], $linha->subject);
        $this->assertSame((string) $metadados['serialNumber'], (string) $linha->serial_number);
        $this->assertSame(
            Carbon::createFromTimestamp((int) $metadados['validFrom_time_t'])->toISOString(),
            $linha->valid_from->toISOString(),
        );
        $this->assertSame(
            Carbon::createFromTimestamp((int) $metadados['validTo_time_t'])->toISOString(),
            $linha->valid_until->toISOString(),
        );

        // E o `document` vem de dentro desse mesmo certificado — o CNPJ está no
        // `subject`, e é dele que a linha guarda o valor.
        $this->assertStringContainsString(self::CNPJ, (string) $linha->subject);
        $this->assertSame(self::CNPJ, $linha->document);
    }

    /**
     * O cofre abre o e-CNPJ **uma vez**, e é isso que este caso trava.
     *
     * `CertificatePkcs12::inspect()` já devolve `cert` e `pkey` e já classificou
     * a leitura — inclusive o container legado, que é a única coisa que o
     * `SerproCertificateIdentity` não sabe fazer. Reabrir os mesmos bytes para
     * extrair o CNPJ custava um segundo `openssl_pkcs12_read` de até 2 MiB por
     * upload, e o ganho era zero: as duas leituras viam o mesmo certificado.
     *
     * **A asserção é sobre o código, e não sobre o resultado, e a razão está
     * escrita porque ela é incomum.** Um teste que conferisse o `document`
     * gravado passaria com as duas leituras: as duas devolvem o mesmo
     * documento, e é por isso que o custo — que era o defeito — não aparece
     * em lugar nenhum do resultado. Medir a contagem de parse exigiria um dublê
     * da identidade, e ela é `final`: nem o `Mockery` a substitui nem uma
     * subclasse a estende. Em vez de afrouxar o `final` por causa de um
     * teste, o que fica é a leitura da fonte, e ela pega o que importa: uma
     * volta à segunda leitura. O comportamento — que a linha grava o documento
     * do certificado certo — é de
     * `test_a_linha_gravada_descreve_o_mesmo_certificado_que_o_cofre_abriu`, e
     * o contrato das duas entradas da identidade é de
     * `Tests\Unit\SerproCertificateIdentityTest`.
     */
    public function test_o_cofre_extrai_o_documento_do_certificado_que_ja_abriu(): void
    {
        $fonte = (string) file_get_contents(app_path('Services/AccountCertificateVault.php'));

        $this->assertStringContainsString(
            '$this->identity->documentFromCertificate($inspected[\'cert\'])',
            $fonte,
            'O cofre tem de pedir o documento ao certificado que o inspect() já devolveu.',
        );

        // A segunda leitura dos mesmos bytes é o que o caso tem de impedir, e a
        // forma dela é uma chamada a `$this->identity->document(`.
        //
        // **A ausência é afirmada sem o nome da variável, e isso é o que a
        // torna estrita.** Com o nome da variável, a checagem seria
        // `$this->identity->document($bytes` — e uma reintrodução que renomeasse
        // o argumento, `$this->identity->document($bytesDoArquivo, $senha)`,
        // passaria por cima dela, reabrindo os bytes sem o teste reclamar. Sem
        // a variável, a única forma de write que casa é a segunda leitura, e o
        // `documentFromCertificate(` da linha de cima não casa, porque o prefixo
        // não é o mesmo: `document(` contra `documentFromCertificate(`.
        $this->assertStringNotContainsString(
            '$this->identity->document(',
            $fonte,
            'O cofre voltou a reabrir os bytes para extrair o documento.',
        );
    }

    /**
     * **Uma duplicata faz a remoção responder `204` e não remover nada.**
     *
     * Este caso não é sobre a garantia de "uma linha corrente" — que é da
     * aplicação e está em
     * `test_substituicao_marca_a_linha_anterior_e_apaga_somente_o_ciphertext`. É
     * sobre o que acontece quando essa garantia **falha** por fora (restauração,
     * `insert` manual, um banco copiado de um ambiente com bug), porque o
     * resultado é o pior dos dois mundos: o escritório recebe a confirmação de
     * que tirou o certificado e continua podendo assinar com ele.
     *
     * As três leituras que produzem isso são `latest('id')` — `supersede()`,
     * `remove()` e `currentFor()`. Com A (id menor) e B (id maior) as duas
     * correntes, o DELETE marca **B**, que é a que `latest('id')` devolve, e
     * A sobra corrente **com o conteúdo cifrado dentro**. O `204` é honesto do
     * ponto de vista da requisição: uma linha foi marcada.
     *
     * O conserto é de inspeção — a linha de **menor** `id` é a que tem de ser
     * marcada à mão —, e por isso o que este teste deixa explícito é o estado
     * perigoso, e não uma correção que este código não faz. Um futuro índice
     * parcial tornaria a duplicata impossível pela entrada, mas não apagaria as
     * que já existem: a verificação continua sendo de operador.
     */
    public function test_duplicata_faz_a_remocao_responder_204_sem_remover_a_linha_que_sobra(): void
    {
        $conta = Account::factory()->create();
        ['file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        // A linha que o upload gravou, e uma duplicata de **maior** `id` — que é
        // a linha que o `latest('id')` alcança, e portanto a que `remove()` vai
        // marcar. A duplicata é inserida **depois** da original, e é essa ordem
        // que produz o estado perigoso: o que sobra corrente é a original, que
        // é a de **menor** `id` e a que nenhuma troca futura alcança.
        $original = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($original);

        $duplicata = AccountCertificate::factory()->create([
            'account_id' => $conta->getKey(),
            'document' => self::CNPJ_ALHEIO,
            'original_filename' => 'duplicata.p12',
        ]);

        // Reaproveita o conteúdo cifrado da linha real, para que a duplicata
        // tenha bytes válidos e a remoção de uma não possa ser confundida com
        // uma coluna vazia.
        $duplicata->forceFill([
            'certificate_encrypted' => $original->certificate_encrypted,
            'password_encrypted' => $original->password_encrypted,
        ])->save();

        $menorId = min($duplicata->getKey(), $original->getKey());
        $maiorId = max($duplicata->getKey(), $original->getKey());

        $this->actingAs($this->membroDe($conta, 'admin'), 'sanctum')
            ->deleteJson(self::ROTA)
            ->assertNoContent();

        // O que `remove()` marcou foi a de **maior** `id` — a única que o
        // `latest('id')` alcança.
        $marcada = AccountCertificate::query()->findOrFail($maiorId);
        $sobrou = AccountCertificate::query()->findOrFail($menorId);

        $this->assertNotNull($marcada->removed_at);
        $this->assertNull($marcada->certificate_encrypted);

        // E a de **menor** `id` continua corrente, com o conteúdo intacto: o
        // `204` que o escritório recebeu não corresponde ao estado do banco.
        $this->assertNull($sobrou->removed_at);
        $this->assertNull($sobrou->replaced_at);
        $this->assertNotNull(
            $sobrou->certificate_encrypted,
            'A linha que sobrou corrente não tem mais o conteúdo cifrado — a duplicata não produziria o estado perigoso.',
        );

        // `currentFor()` devolve justamente essa, e a leitura da tela devolve
        // metadados de um certificado que o operador acabou de remover.
        $atual = AccountCertificate::currentFor($conta->getKey());

        $this->assertNotNull($atual);
        $this->assertSame($menorId, $atual->getKey());
        $this->assertSame($sobrou->certificateBytes(), $atual->certificateBytes());
        $this->assertTrue($atual->valid_until->isFuture());
    }

    public function test_escrita_em_modo_suporte_registra_a_auditoria_sem_segredo(): void
    {
        $alvo = Account::factory()->create();

        $super = User::factory()->create(['is_super_admin' => true]);
        $casa = Account::factory()->create();
        AccountUser::create(['account_id' => $casa->getKey(), 'user_id' => $super->getKey(), 'role' => 'admin']);
        $super->forceFill(['current_account_id' => $alvo->getKey()])->save();

        ['bytes' => $bytes, 'file' => $arquivo] = $this->pfx('escritorio.p12');

        $this->actingAs($super->refresh(), 'sanctum')
            ->post(self::ROTA, ['certificate' => $arquivo, 'password' => self::SENHA], $this->jsonHeaders())
            ->assertOk();

        $log = SupportAccessLog::query()->sole();

        $this->assertSame($super->getKey(), $log->super_admin_user_id);
        $this->assertSame($alvo->getKey(), $log->account_id);
        $this->assertSame('create', $log->action);
        $this->assertSame('account_certificates', $log->metadata['resource'] ?? null);

        // O que a auditoria registra é quem fez o quê: o documento, que a API
        // publica. Nem o arquivo, nem a senha, nem o texto cifrado.
        $this->assertSame(self::CNPJ, $log->metadata['document'] ?? null);

        $registro = (string) $log->toJson();

        $this->assertStringNotContainsString(self::SENHA, $registro);
        $this->assertStringNotContainsString(base64_encode($bytes), $registro);
        $this->assertStringNotContainsString(
            (string) AccountCertificate::query()->sole()->getRawOriginal('certificate_encrypted'),
            $registro,
        );
    }

    public function test_colunas_cifradas_e_documento_nao_sao_preenchiveis(): void
    {
        // `Fillable` é a camada por onde um `fill($request->validated())`
        // passaria, e é onde a garantia de que nenhum segredo atravessa por
        // requisição tem de estar. Quem grava o segredo de verdade é o cofre, e
        // ele o cifra neste mesmo passo.
        $linha = AccountCertificate::factory()->create();

        foreach (['certificate_encrypted', 'password_encrypted', 'document'] as $coluna) {
            $this->assertFalse(
                $linha->isFillable($coluna),
                sprintf('A coluna %s não pode ser preenchível a partir de uma requisição.', $coluna),
            );
        }

        $cifradoAntes = $linha->getRawOriginal('certificate_encrypted');
        $senhaAntes = $linha->getRawOriginal('password_encrypted');
        $documentoAntes = $linha->document;

        $linha->fill([
            'subject' => 'subject-inventado',
            'certificate_encrypted' => 'cifrado-inventado',
            'password_encrypted' => 'senha-inventada',
            'document' => '99999999999999',
        ]);

        // Nada do que foi proibido chegou à linha. O `subject` entrou, porque
        // ele é `Fillable` — metadado não secreto, que é o que a lista existe
        // para deixar passar.
        $this->assertSame($cifradoAntes, $linha->getRawOriginal('certificate_encrypted'));
        $this->assertSame($senhaAntes, $linha->getRawOriginal('password_encrypted'));
        $this->assertSame($documentoAntes, $linha->document);
        $this->assertSame('subject-inventado', $linha->subject);
    }

    /**
     * @return array<string, string>
     */
    private function jsonHeaders(): array
    {
        return ['Accept' => 'application/json'];
    }

    /**
     * @param  array<int, string>  $keys
     * @return list<string>
     */
    private function chavesOrdenadas(mixed $valor): array
    {
        $this->assertIsArray($valor);

        $chaves = array_keys($valor);
        sort($chaves);

        return $chaves;
    }

    /**
     * Um PKCS#12 descartável, gerado agora e com o CNPJ no subject — que é onde
     * o certificado de e-CNPJ o carrega. Nenhum fixture de PFX é versionado.
     *
     * @return array{bytes: string, file: UploadedFile}
     */
    /**
     * @param  array<string, string>|null  $subject  o subject inteiro do
     *                                               certificado de descarte, quando
     *                                               o caso precisa de uma DN longa
     */
    private function pfx(
        string $name,
        string $password = self::SENHA,
        int $dias = 365,
        string $document = self::CNPJ,
        ?array $subject = null,
    ): array {
        $assunto = $subject === null
            ? [
                'CN' => 'Escritorio Contabil Andre Siqueira:'.$document,
                'serialNumber' => $document,
            ]
            : $subject + ['serialNumber' => $document];
        $config = $this->opensslConfig();

        $chave = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($chave, 'O par de chaves de descarte não pôde ser gerado.');

        $csr = openssl_csr_new($assunto, $chave, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($csr, 'O CSR de descarte não pôde ser gerado.');

        // Validade de zero dia deixa `notAfter` no segundo corrente, o que já
        // torna o certificado vencido na leitura.
        $certificado = openssl_csr_sign($csr, null, $chave, $dias, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificado, 'O certificado de descarte não pôde ser assinado.');

        $bytes = '';
        $this->assertTrue(
            openssl_pkcs12_export($certificado, $bytes, $chave, $password, $config),
            'O PFX de descarte não pôde ser exportado.',
        );

        return ['bytes' => $bytes, 'file' => UploadedFile::fake()->createWithContent($name, $bytes)];
    }

    /**
     * @return array{config?: string}
     */
    private function opensslConfig(): array
    {
        return file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];
    }

    /**
     * Um container que este OpenSSL recusa por não trazer o provedor legado, ou
     * `null` com o motivo em `$legacyUnavailable`.
     *
     * O gerador é uma cópia de propósito do de
     * `tests/Unit/CertificatePkcs12Test.php`, que é arquivo congelado da Task 2
     * e não pode ser editado — e um trait compartilhado exigiria editá-lo, o que
     * invalidaria a prova de que a extração da leitura não mudou nada.
     *
     * @param  list<string>  $pbe
     */
    private function containerLegado(array $pbe, string $rotulo): ?string
    {
        $binario = $this->opensslBinario();

        if ($binario === null) {
            $this->legacyUnavailable = 'o executável openssl não está no PATH';

            return null;
        }

        $diretorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'account-certificate-'.$rotulo.'-'.bin2hex(random_bytes(6));

        if (! @mkdir($diretorio, 0700, true) && ! is_dir($diretorio)) {
            $this->legacyUnavailable = sprintf('não foi possível criar o diretório temporário em %s', sys_get_temp_dir());

            return null;
        }

        $certificado = $diretorio.DIRECTORY_SEPARATOR.'cert.pem';
        $chave = $diretorio.DIRECTORY_SEPARATOR.'key.pem';
        $pfx = $diretorio.DIRECTORY_SEPARATOR.'legacy.pfx';

        try {
            if (! $this->runOpenssl($binario, [
                'req', '-x509', '-newkey', 'rsa:1024', '-nodes',
                '-keyout', $chave, '-out', $certificado,
                '-days', '1', '-subj', '/CN=Legado',
            ], $diretorio)) {
                $this->legacyUnavailable = 'o par autossinado de descarte não pôde ser gerado';

                return null;
            }

            if (! $this->runOpenssl($binario, array_merge([
                'pkcs12', '-export', '-legacy',
                '-in', $certificado, '-inkey', $chave,
                '-passout', 'pass:'.self::SENHA, '-out', $pfx,
            ], $pbe), $diretorio)) {
                $this->legacyUnavailable = sprintf('o openssl deste ambiente recusou o export legado com %s', $rotulo);

                return null;
            }

            $bytes = file_get_contents($pfx);

            if ($bytes === false || $bytes === '') {
                $this->legacyUnavailable = 'o export produziu um arquivo vazio';

                return null;
            }

            $lidos = [];

            if (@openssl_pkcs12_read($bytes, $lidos, self::SENHA)) {
                $this->legacyUnavailable = sprintf(
                    'o openssl deste ambiente abre o container com %s, então a leitura não falha e a classificação não tem o que ler',
                    $rotulo,
                );

                return null;
            }

            // A leitura de conferência deixou o erro dela na fila desta thread, e
            // a fila é por thread: quem vem depois leria um erro que não é desta.
            while (openssl_error_string() !== false) {
                // Esvazia a fila deixada pela leitura de conferência.
            }

            return $bytes;
        } finally {
            foreach ([$pfx, $chave, $certificado] as $arquivo) {
                if (is_file($arquivo)) {
                    @unlink($arquivo);
                }
            }

            @rmdir($diretorio);
        }
    }

    private function opensslBinario(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $caminho) {
            $candidato = rtrim($caminho, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'openssl';

            if ($caminho !== '' && is_executable($candidato)) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $argumentos
     */
    private function runOpenssl(string $binario, array $argumentos, string $diretorio): bool
    {
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descritores = [
            0 => ['file', $nulo, 'r'],
            1 => ['file', $nulo, 'a'],
            2 => ['file', $nulo, 'a'],
        ];

        $processo = @proc_open(array_merge([$binario], $argumentos), $descritores, $pipelines, $diretorio);

        if (! is_resource($processo)) {
            return false;
        }

        foreach ($pipelines as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return proc_close($processo) === 0;
    }

    private function membroDe(Account $conta, string $papel = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $conta->getKey(), 'user_id' => $user->getKey(), 'role' => $papel]);
        $user->forceFill(['current_account_id' => $conta->getKey()])->save();

        return $user->refresh();
    }
}
