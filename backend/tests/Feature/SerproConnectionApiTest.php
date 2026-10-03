<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Http\Resources\SerproConnectionResource;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\SerproClient;
use App\Services\SerproException;
use App\Services\SerproTokenPair;
use App\Services\SerproTokenProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SerproConnectionApiTest extends TestCase
{
    use RefreshDatabase;

    /** O CNPJ que o certificado de teste carrega; é o mesmo do factory. */
    private const CNPJ = '12345678000195';

    private const SENHA = 'senha-do-pfx';

    private const SEGREDO = 'segredo-da-plataforma';

    /**
     * Os PFX que subiram por um caminho de **arquivo real**, para apagar no fim
     * da classe.
     *
     * Um `UploadedFile` real tem um caminho em disco e ninguém o apaga por nós:
     * sem esta lista, o helper de PFX real deixaria um certificado — que é
     * material de assinatura, mesmo de descarte — no `/tmp` do CI.
     *
     * **A lista é `static` porque é `tearDownAfterClass()` que a consome**, e um
     * método estático não enxerga o estado de instância. Uma lista de instância
     * pareceria funcionar e não limparia nada — que foi exatamente o que a
     * primeira versão deste arquivo tinha, e a primeira versão também limpava por
     * `glob`, o que apagava o PFX de outra execução concorrente na mesma máquina.
     * Registrar o caminho e apagar o caminho registrado são o mesmo dado.
     *
     * @var list<string>
     */
    private static array $arquivosTemporarios = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum endpoint desta tela fala com o provedor: a credencial é
        // configurada aqui e usada em outro processo. Qualquer chamada HTTP
        // seria um desvio de rota, não um efeito colateral tolerável.
        Http::preventStrayRequests();
    }

    public static function tearDownAfterClass(): void
    {
        // Só os caminhos que esta execução criou. Um `glob` por prefixo
        // apagaria também os PFX de outra execução desta suíte rodando ao mesmo
        // tempo na mesma máquina — que é a corrida que o `tempnam` do helper
        // existe para evitar, e que um `glob` desfaria na limpeza.
        foreach (self::$arquivosTemporarios as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }

        self::$arquivosTemporarios = [];

        parent::tearDownAfterClass();
    }

    public function test_apenas_super_admin_salva_conexao_unica(): void
    {
        ['bytes' => $bytes, 'file' => $file] = $this->pfx();

        $this->actingAs($this->memberOf(Account::factory()->create(), 'admin'), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertForbidden();

        $this->assertDatabaseCount('serpro_connections', 0);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.contracting_document', self::CNPJ);

        $this->assertDatabaseCount('serpro_connections', 1);

        $stored = SerproConnection::sole();

        // O documento contratante é extraído do certificado, nunca copiado do
        // corpo da requisição: a request entrega só o PFX.
        $this->assertSame(self::CNPJ, $stored->contratante_numero);
        $this->assertSame(2, $stored->contratante_tipo);
        $this->assertStringContainsString(self::CNPJ, (string) $stored->certificate_subject);
        $this->assertNotNull($stored->certificate_serial_number);
        $this->assertNotNull($stored->certificate_valid_from);
        $this->assertTrue($stored->certificate_valid_until->isFuture());

        // O que é guardado é o cifrado, e o cifrado ainda abre: sem esta
        // segunda metade, "gravou algo" e "guardou a credencial" seriam a mesma
        // afirmação.
        $this->assertNotSame(self::SEGREDO, $stored->getRawOriginal('consumer_secret_encrypted'));
        $this->assertNotSame(self::SENHA, $stored->getRawOriginal('certificate_password_encrypted'));
        $this->assertNotSame($bytes, $stored->getRawOriginal('certificate_encrypted'));
        $this->assertSame(self::SEGREDO, $stored->consumerSecret());
        $this->assertSame(self::SENHA, $stored->certificatePassword());
        $this->assertSame($bytes, $stored->certificateBytes());

        // A segunda rede: serializar a linha inteira não devolve segredo.
        $this->assertArrayNotHasKey('consumer_secret_encrypted', $stored->toArray());
        $this->assertArrayNotHasKey('certificate_encrypted', $stored->toArray());
        $this->assertArrayNotHasKey('certificate_password_encrypted', $stored->toArray());

        // A segunda gravação rotaciona a mesma linha: a credencial é uma só.
        ['file' => $rotated] = $this->pfx();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao-nova',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $rotated,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertOk();

        $this->assertDatabaseCount('serpro_connections', 1);
        $this->assertSame('chave-de-integracao-nova', $stored->fresh()->consumer_key);
    }

    public function test_banco_recusa_uma_segunda_credencial(): void
    {
        // "Uma única credencial da plataforma" é a premissa da spec inteira: se
        // ela vale só por convenção de aplicação, a primeira vez que duas
        // gravações se cruzarem deixa duas linhas, e `current()` — que é um
        // `first()` sem ordem — se apega a uma delas enquanto a outra guarda
        // um PFX e um segredo que nada mais rotaciona, invalida ou exibe.
        $unicos = array_values(array_filter(
            Schema::getIndexes('serpro_connections'),
            fn (array $index): bool => $index['unique'] === true
                && $index['primary'] === false
                && in_array('singleton', $index['columns'], true),
        ));

        $this->assertCount(1, $unicos, 'A unicidade da credencial tem de ser garantida pelo índice do banco.');

        SerproConnection::factory()->create();

        try {
            SerproConnection::factory()->create();

            $this->fail('Uma segunda credencial deveria ser recusada pelo banco.');
        } catch (UniqueConstraintViolationException) {
            // esperado: é o índice que impede a segunda linha.
        }

        // A recusa é da segunda gravação, não da que já existia: a credencial
        // guardada continua legível e continua sendo uma só.
        $this->assertSame(1, SerproConnection::query()->count());
        $this->assertSame(self::CNPJ, SerproConnection::current()?->contratante_numero);
    }

    public function test_perdedor_da_corrida_recebe_erro_nomeado_e_nao_500(): void
    {
        ['file' => $file] = $this->pfx();
        $super = User::factory()->create(['is_super_admin' => true]);

        // O operador recebe uma frase boa; o log não pode ficar com nada, porque
        // recusa de índice único nesta tabela tem uma só explicação e o conserto
        // (recarregar a tela) depende de ela existir. A exceção do banco traz o
        // `insert` completo nos seus valores, então o log leva a classe e a
        // frase fixa — nunca o SQL, que carregaria segredo e PFX cifrado.
        Log::spy();

        // A corrida que a trava do manager não cobre: `lockForUpdate()` não
        // trava a linha que ainda não existe (e o SQLite nem compila o
        // `for update`), então as duas primeiras gravações chegam as duas
        // lendo "não há credencial". A segunda só é barrada pelo índice — e
        // precisa chegar ao operador como erro nomeado, não como 500.
        $chegouAntes = false;

        DB::listen(function (QueryExecuted $query) use (&$chegouAntes): void {
            if ($chegouAntes || ! str_contains($query->sql, 'from "serpro_connections"')) {
                return;
            }

            $chegouAntes = true;

            DB::table('serpro_connections')->insert([
                'consumer_key' => 'chave-que-chegou-primeiro',
                'consumer_secret_encrypted' => Crypt::encryptString('segredo-que-chegou-primeiro'),
                'contratante_numero' => self::CNPJ,
            ]);
        });

        $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-que-chegou-depois',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consumer_key');

        $this->assertTrue($chegouAntes, 'A gravação perdedora precisa ter encontrado a linha da outra.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                $registro = $message.' '.json_encode($context, JSON_THROW_ON_ERROR);

                $this->assertStringContainsString('singleton', $registro);
                $this->assertStringNotContainsString(self::SEGREDO, $registro);
                $this->assertStringNotContainsString(self::SENHA, $registro);
                $this->assertStringNotContainsString('insert into', $registro);

                return true;
            });

        // A gravação perdedora não deixou linha nenhuma. A linha da vencedora
        // aqui está na mesma transação e cai junto no rollback — é o que a
        // corrida real não faz, e é o que a garantia do banco prova no teste
        // anterior: uma escrita recusada convive com a linha que já existia.
        $this->assertLessThanOrEqual(1, SerproConnection::query()->count());
    }

    public function test_rotacao_invalida_o_token_em_cache(): void
    {
        ['file' => $file] = $this->pfx();
        $super = User::factory()->create(['is_super_admin' => true]);

        // Um par em cache vale para a credencial que o emitiu; continuar usando
        // depois da rotação é chamar o provedor com uma credencial que ele já
        // não reconhece.
        Cache::put('serpro:token-pair', new SerproTokenPair('access-antigo', 'jwt-antigo', 2008), 600);

        $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertOk();

        $this->assertNull(Cache::get('serpro:token-pair'));
    }

    public function test_membro_le_metadados_mas_nao_edita(): void
    {
        $this->getJson('/api/serpro/connection')->assertUnauthorized();

        SerproConnection::factory()->create();

        // Qualquer membro da conta corrente lê: a credencial é da plataforma, e
        // ler a identidade do contrato não é operar a conta.
        $this->actingAs($this->memberOf(Account::factory()->create(), 'user'), 'sanctum')
            ->getJson('/api/serpro/connection')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.contracting_document', self::CNPJ);

        $this->actingAs($this->memberOf(Account::factory()->create(), 'user'), 'sanctum')
            ->putJson('/api/serpro/connection', ['consumer_key' => 'chave'])
            ->assertForbidden();

        $this->assertSame(0, SerproConnection::query()->where('consumer_key', 'chave')->count());

        // Sem conta corrente não há membro de nada, mesmo autenticado.
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/serpro/connection')
            ->assertForbidden();
    }

    public function test_segredo_omitido_preserva_valor_cifrado(): void
    {
        $connection = SerproConnection::factory()->create();
        $before = $connection->consumer_secret_encrypted;

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', ['consumer_key' => 'nova-chave'], $this->jsonHeaders())
            ->assertOk()
            ->assertJsonMissingPath('data.consumer_secret');

        $fresh = $connection->fresh();

        $this->assertSame($before, $fresh->consumer_secret_encrypted);
        $this->assertSame('nova-chave', $fresh->consumer_key);

        // Trocar a chave não pode tragar o segredo junto, e o segredo guardado
        // continua legível pela credencial, ou seja, não foi destruído.
        $this->assertStringNotContainsString($fresh->consumerSecret(), (string) $before);

        // Uma chave em branco é "preserve o que está gravado", como a tela
        // promete — e não uma recusa que impediria rotacionar só o segredo.
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', ['consumer_key' => '  '], $this->jsonHeaders())
            ->assertOk();

        $this->assertSame('nova-chave', $connection->fresh()->consumer_key);
    }

    public function test_documento_divergente_bloqueia_sem_rede(): void
    {
        ['bytes' => $bytes] = $this->pfx();

        // A coluna guarda um documento que não é o do certificado guardado: é o
        // estado que um `contratante_numero` digitado à mão produziria, e que o
        // provedor responderia com um 403 indistinguível de senha errada.
        SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
            'contratante_numero' => '27865757000102',
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Uma identidade divergente deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertStringContainsString('27865757000102', $exception->getMessage());
            $this->assertStringContainsString(self::CNPJ, $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_autenticacao_exige_a_mesma_identidade(): void
    {
        ['bytes' => $bytes] = $this->pfx();

        SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
            'contratante_numero' => '27865757000102',
        ]);

        // A guarda também é do token, e não só da chamada: pedir token com um
        // certificado que não é o do contratante gasta uma autenticação recusada
        // para descobrir o que já se sabe localmente.
        try {
            resolve(SerproTokenProvider::class)->pair();

            $this->fail('Uma identidade divergente deve levantar SerproException na autenticação.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertStringContainsString(self::CNPJ, $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_certificado_guardado_ilegivel_e_recusado_sem_rede(): void
    {
        // Bytes que não são um PFX podem entrar por migração, por restauração ou
        // por escrita fora do manager. A identidade ilegível é uma falha nomeada
        // da credencial, não um `ValidationException` de upload vazando para o
        // chamador que só sabe tratar `SerproException`.
        SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString('isto nao e um pkcs12'),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Um certificado ilegível deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertStringContainsString('não pôde ser lido', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_resposta_nao_contem_segredo_ou_certificado(): void
    {
        ['bytes' => $bytes, 'file' => $file] = $this->pfx();
        $super = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao-1234',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertOk();

        $response = $this->actingAs($super, 'sanctum')->getJson('/api/serpro/connection');

        $response->assertOk()
            ->assertJsonMissingPath('data.consumer_secret')
            ->assertJsonMissingPath('data.certificate_encrypted')
            ->assertJsonMissingPath('data.certificate_password_encrypted')
            ->assertJsonMissingPath('data.password');

        // A lista é fechada e é a mesma que `frontend/app/types/serpro.ts`
        // declara: nenhuma chave a mais (o segredo) e nenhuma a menos (o que a
        // tela precisa para reconhecer a credencial).
        $this->assertSame([
            'certificate_not_after',
            'certificate_not_before',
            'certificate_serial',
            'certificate_subject',
            'configured',
            'consumer_key_hint',
            'contracting_document',
            'updated_at',
        ], $this->sortedKeys($response->json('data')));

        $content = (string) $response->getContent();

        $this->assertStringNotContainsString(self::SEGREDO, $content);
        $this->assertStringNotContainsString(self::SENHA, $content);
        $this->assertStringNotContainsString('chave-de-integracao-1234', $content);
        $this->assertStringNotContainsString(base64_encode($bytes), $content);
        $this->assertStringNotContainsString(bin2hex($bytes), $content);
        $this->assertStringNotContainsString((string) SerproConnection::sole()->getRawOriginal('consumer_secret_encrypted'), $content);

        // A pista da chave ainda reconhece a chave guardada.
        $this->assertStringContainsString('1234', (string) $response->json('data.consumer_key_hint'));
    }

    public function test_a_pista_da_chave_nao_vira_a_chave_quando_ela_e_curta(): void
    {
        // A chave real do SERPRO é longa e a pista são quatro caracteres de dezenas.
        // O caso oposto é o que a guarda existe para: uma chave de poucos
        // caracteres — teste, homologação — onde `substr($key, -4)` seria quase a
        // chave inteira, e a pista passaria a ser a chave com um prefixo só.
        // Modelos em memória, e não linhas: o índice de `singleton` só admite uma
        // credencial, e a pista não consulta nada além da chave que a resource
        // recebeu.
        $this->assertSame('••••', $this->pistaDe(new SerproConnection(['consumer_key' => 'ABC123'])));

        // Nove caracteres é onde a guarda antiga (`strlen > 8`) vazava: `••••`
        // mais os quatro últimos de uma chave de nove é quase a chave inteira,
        // e a pista deixava de ser pista.
        $this->assertSame('••••', $this->pistaDe(new SerproConnection(['consumer_key' => 'ABCDEFGHI'])));

        // Dezesseis é o piso: uma chave de dezesseis caracteres já tem um
        // "final" que não é a chave, e é a partir dele que a pista vale.
        $this->assertSame('••••MNOP', $this->pistaDe(new SerproConnection(['consumer_key' => 'ABCDEFGHIJKLMNOP'])));

        // E a chave de verdade, que é longa, continua reconhecível.
        $this->assertSame('••••6789', $this->pistaDe(new SerproConnection([
            'consumer_key' => 'chave-de-integracao-da-plataforma-0001234-56789',
        ])));
    }

    public function test_conexao_ainda_nao_cadastrada_responde_desconfigurada_e_nao_500(): void
    {
        // A tela que o super_admin abre primeiro numa instalação nova é esta, e
        // ela tem um ramo só para a credencial ausente
        // (`frontend/app/pages/admin/serpro.vue`, `unconfiguredNotice`) que
        // depende desta resposta. Todo outro teste de leitura cria a linha
        // antes, então o ramo sem linha só era alcançado por um `401` que nunca
        // chega ao controller — e um `500` aqui seria a primeira coisa que o
        // operador veria, em tela cheia, logo depois de instalar.
        $this->getJson('/api/serpro/connection')->assertUnauthorized();

        $this->assertDatabaseCount('serpro_connections', 0);

        $response = $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->getJson('/api/serpro/connection');

        $response->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.consumer_key_hint', null)
            ->assertJsonPath('data.contracting_document', null)
            ->assertJsonPath('data.certificate_subject', null)
            ->assertJsonPath('data.certificate_serial', null)
            ->assertJsonPath('data.certificate_not_before', null)
            ->assertJsonPath('data.certificate_not_after', null)
            ->assertJsonPath('data.updated_at', null);

        // O contrato é o mesmo da credencial cadastrada: a tela decide o que
        // mostrar pela forma da resposta, não por um `404` — o recurso existe,
        // o que não existe é a configuração.
        $this->assertSame([
            'certificate_not_after',
            'certificate_not_before',
            'certificate_serial',
            'certificate_subject',
            'configured',
            'consumer_key_hint',
            'contracting_document',
            'updated_at',
        ], $this->sortedKeys($response->json('data')));
    }

    public function test_coluna_cifrada_e_documento_contratante_nao_sao_preenchiveis(): void
    {
        // `Fillable` é a camada por onde um `fill($request->validated())`
        // passaria, e é onde a garantia de que nenhum segredo atravessa por
        // request tem de estar — não no `prohibited` da request, que é uma
        // linha. Um `fill()` com qualquer um desses atributos tem de ser
        // recusado pelo modelo, e quem grava o segredo de verdade é o gerenciador
        // com `forceCreate`.
        $connection = SerproConnection::factory()->create();

        $this->assertFalse(
            $connection->isFillable('consumer_secret_encrypted'),
            'O segredo da credencial não pode ser preenchível a partir de uma requisição.',
        );
        $this->assertFalse(
            $connection->isFillable('certificate_encrypted'),
            'Os bytes do PFX não podem ser preenchíveis a partir de uma requisição.',
        );
        $this->assertFalse(
            $connection->isFillable('certificate_password_encrypted'),
            'A senha do certificado não pode ser preenchível a partir de uma requisição.',
        );
        $this->assertFalse(
            $connection->isFillable('contratante_numero'),
            'O documento contratante é extraído do certificado e não pode vir do corpo da requisição.',
        );

        // E o `fill()` realmente não escreve nada: um `fill` com tudo junto não
        // altera nem o segredo nem o documento.
        $antes = $connection->getRawOriginal('consumer_secret_encrypted');

        $connection->fill([
            'consumer_key' => 'chave-que-nao-deveria-passar',
            'consumer_secret_encrypted' => 'cifrado-inventado',
            'certificate_encrypted' => 'pfx-inventado',
            'certificate_password_encrypted' => 'senha-inventada',
            'contratante_numero' => '99999999999999',
        ]);

        $this->assertSame($antes, $connection->getRawOriginal('consumer_secret_encrypted'));
        $this->assertSame(self::CNPJ, $connection->contratante_numero);
        $this->assertNull($connection->certificate_encrypted);
        $this->assertNull($connection->certificate_password_encrypted);
    }

    public function test_contratante_numero_da_request_e_recusado(): void
    {
        ['file' => $file] = $this->pfx();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
                'contratante_numero' => '27865757000102',
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contratante_numero');

        $this->assertDatabaseCount('serpro_connections', 0);
    }

    public function test_certificado_sem_cnpj_ou_com_cnpjs_divergentes_e_recusado(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);

        // Sem CNPJ no assunto: um certificado que não identifica o contratante
        // não pode virar a fonte da identidade.
        ['file' => $semCnpj] = $this->pfx(['CN' => 'CERTIFICADO SEM DOCUMENTO']);

        $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $semCnpj,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate')
            ->assertJsonPath('errors.certificate.0', 'O certificado do contratante não informa o CNPJ da empresa.');

        // Dois CNPJs no mesmo certificado: qual deles é o contratante? Nenhum
        // pode ser escolhido em silêncio.
        ['file' => $divergente] = $this->pfx(['CN' => self::CNPJ.':27865757000102']);

        $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $divergente,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate')
            ->assertJsonPath('errors.certificate.0', 'O certificado do contratante informa mais de um CNPJ, e não é possível saber qual é o contratante.');

        // Formato de CNPJ que não fecha é um defeito diferente de "não há
        // documento": o operador precisa saber qual dos dois aconteceu.
        ['file' => $invalido] = $this->pfx(['CN' => 'CERTIFICADO:99999999999999']);

        $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $invalido,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate')
            ->assertJsonPath('errors.certificate.0', 'O certificado do contratante informa um CNPJ inválido.');

        // Um segmento de CN com catorze caracteres alfanuméricos e nenhum dígito
        // é razão social (`AGROINDUSTRIAL`), não documento: o comprimento
        // normalizado sozinho acusaria um CNPJ inválido onde não há CNPJ.
        ['file' => $razaoSocial] = $this->pfx(['CN' => 'AGROINDUSTRIAL:1234567']);

        $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $razaoSocial,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate')
            ->assertJsonPath('errors.certificate.0', 'O certificado do contratante não informa o CNPJ da empresa.');

        $this->assertDatabaseCount('serpro_connections', 0);
    }

    public function test_certificado_vencido_e_recusado_no_upload(): void
    {
        // Validade de zero dia: `notAfter` cai no segundo corrente, o que já
        // o torna vencido na leitura.
        ['file' => $file] = $this->pfx(null, 0);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        $this->assertDatabaseCount('serpro_connections', 0);
    }

    public function test_senha_divergente_ou_sem_certificado_confiavel_e_recusada(): void
    {
        ['bytes' => $bytes] = $this->pfx();

        $comCertificado = SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
        ]);

        // A senha é a chave de leitura do PFX guardado; gravá-la sem conferir é
        // deixar a credencial guardar um segredo que não abre nada.
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->putJson('/api/serpro/connection', ['password' => 'senha-errada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(
            self::SENHA,
            $comCertificado->fresh()->certificatePassword(),
        );

        SerproConnection::query()->delete();

        // Sem certificado guardado não há o que conferir, e a senha é recusada
        // em vez de gravada: uma linha sem PFX (factory, restauração) não pode
        // virar uma credencial que nada consegue ler.
        SerproConnection::factory()->create();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->putJson('/api/serpro/connection', ['password' => 'senha-qualquer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertNull(SerproConnection::sole()->certificatePassword());
    }

    public function test_senha_conferida_com_certificado_guardado_ilegivel_e_recusada_e_nao_500(): void
    {
        // Trocar a senha sem reenviar o PFX é caminho suportado, e ele abre o
        // certificado guardado para conferir a senha nova. Um cifrado que não
        // abre — chave de aplicação trocada, coluna truncada, restauração de
        // outro ambiente — não pode virar `500` no operador que está tentando
        // recuperar a credencial: o conserto é reenviar o certificado.
        ['bytes' => $bytes] = $this->pfx();

        $comCertificado = SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
        ]);
        $comCertificado->forceFill(['certificate_encrypted' => 'cifrado-que-nao-abre'])->save();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->putJson('/api/serpro/connection', ['password' => 'senha-nova'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password')
            ->assertJsonPath('errors.password.0', 'Não foi possível conferir a senha: o certificado do contratante gravado não pôde ser lido. Envie o certificado de novo.');

        // A senha nova não foi gravada: uma linha que não abre continua melhor
        // do que uma linha com a senha trocada e o mesmo certificado ilegível.
        $this->assertSame(
            self::SENHA,
            Crypt::decryptString((string) $comCertificado->fresh()->getRawOriginal('certificate_password_encrypted')),
        );
    }

    public function test_documento_contratante_ausente_ou_vazio_na_divergencia_e_dito_por_extenso(): void
    {
        // A coluna não aceita `null`, mas aceita a string vazia — e uma linha
        // restaurada ou escrita fora do manager chega assim. "é do CNPJ X, e não
        // ." não diz nada, e é justamente o caso em que o operador precisa saber
        // que o problema não é divergência: é documento faltando.
        ['bytes' => $bytes] = $this->pfx();

        SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
            'contratante_numero' => '',
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );

            $this->fail('Uma credencial sem documento contratante gravado deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertStringNotContainsString('e não .', $exception->getMessage());
            $this->assertStringContainsString(
                'a credencial não tem documento contratante gravado',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_tipo_do_contratante_vem_do_documento_gravado_e_nao_do_padrao_do_banco(): void
    {
        // Documento e tipo são o mesmo dado em duas colunas: se o extrator um dia
        // aceitar um CPF, `1` é o valor certo e a coluna não pode continuar
        // dizendo `2` só porque é o que o banco põe por omissão. Começando de
        // uma linha com `1`, a rotação do certificado tem de consertar a coluna.
        ['file' => $file] = $this->pfx();

        $connection = SerproConnection::factory()->create(['contratante_tipo' => 1]);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertOk();

        $this->assertSame(2, $connection->fresh()->contratante_tipo);
        $this->assertSame(self::CNPJ, $connection->fresh()->contratante_numero);
    }

    /**
     * Os dois `NotSent` deste arquivo — certificado ilegível e pasta temporária
     * sem gravação — significam "falha nossa, antes de qualquer requisição", e
     * cada um precisa ser testemunhado no produtor. O veredito do teste de
     * conectividade não serve: ele fixa `certificado` ou `provedor` no guard,
     * antes de olhar a falha, e passaria igual com `DoNotRetry`.
     *
     * O terceiro produtor é o segredo ilegível, e o testify está em
     * `SerproTokenProviderTest::test_um_segredo_ilegivel_vira_falha_nomeada_e_nao_excecao_de_cifra`:
     * ele mora em outra classe, com outra forma de chegar lá, e este arquivo
     * não o exercita. O nome do teste diz o que ele cobre, para ninguém ler
     * "cada falha local" e procurar aqui um terceiro produtor.
     */
    public function test_certificado_ilegivel_e_pasta_sem_gravacao_sao_nomeados_como_nada_enviado(): void
    {
        // 1. O certificado guardado não abre com a chave de aplicação atual: nada
        //    foi enviado e recadastrar o certificado é o conserto — não há
        //    requisição recusada a reter.
        ['bytes' => $bytes] = $this->pfx();
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
        ]);
        $connection->forceFill(['certificate_encrypted' => 'cifrado-que-nao-abre'])->save();

        $falha = $this->falhaDoGateway();
        $this->assertSame(SerproFailure::NotSent, $falha->failure);
        $this->assertStringNotContainsString('cifrado-que-nao-abre', $falha->getMessage());

        // 2. A pasta efêmera do PFX recusou a gravação. Mesmo formato de falha, e
        //    o mesmo `DoNotRetry` seria mentira: ele diria "recadastre a
        //    credencial" para uma credencial inteira.
        $connection->refresh()->forceFill([
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
        ])->save();
        $this->tempDirIngravavel();

        $falha = $this->falhaDoGateway();
        $this->assertSame(SerproFailure::NotSent, $falha->failure);
        $this->assertStringContainsString('diretório temporário', $falha->getMessage());
        $this->assertStringNotContainsString(storage_path(), $falha->getMessage());

        Http::assertNothingSent();
    }

    public function test_credencial_incompleta_na_primeira_gravacao_e_recusada(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->putJson('/api/serpro/connection', ['consumer_key' => 'chave-de-integracao'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consumer_secret', 'certificate']);

        $this->assertDatabaseCount('serpro_connections', 0);
    }

    public function test_a_credencial_reusa_o_ecnpj_da_conta_1_sem_segunda_copia(): void
    {
        // A conta 1 é a primeira `Account` do banco — a definição que o seed
        // local usa —, e o e-CNPJ dela já gravado em Configurações é o que a
        // flag `use_account_certificate` aponta.
        $account = Account::factory()->create();
        $office = AccountCertificate::factory()->create(['account_id' => $account->getKey()]);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'use_account_certificate' => '1',
            ], $this->jsonHeaders())
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.contracting_document', $office->document);

        $connection = SerproConnection::sole();

        // A referência é à conta; os bytes ficam na linha dela, não aqui —
        // que é o que "sem segunda cópia" significa de fato.
        $this->assertSame($account->getKey(), $connection->contracting_account_id);
        $this->assertSame($office->document, $connection->contratante_numero);
        $this->assertNull($connection->certificate_encrypted);
        $this->assertNull($connection->certificate_password_encrypted);

        // E o material resolve por leitura: a linha corrente da conta é quem
        // fornece o PFX e a senha — uma troca do e-CNPJ pela conta não exige
        // nenhuma ação nesta credencial.
        $this->assertSame('pfx-de-descarte', $connection->certificateBytes());
        $this->assertSame('senha-de-descarte', $connection->certificatePassword());
        $this->assertTrue($connection->hasCertificate());
    }

    public function test_a_credencial_segue_o_certificado_novo_da_conta_1(): void
    {
        // Rotacionar o e-CNPJ do escritório não reaponta a credencial: a
        // resolução é pela linha corrente da conta, e é a nova linha que passa
        // a responder.
        $account = Account::factory()->create();
        AccountCertificate::factory()->create(['account_id' => $account->getKey()]);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'use_account_certificate' => '1',
            ], $this->jsonHeaders())
            ->assertOk();

        $novo = AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => '27865757000102',
        ]);
        // A anterior sai de vigência como o cofre a marcaria.
        AccountCertificate::query()
            ->where('account_id', $account->getKey())
            ->where('id', '!=', $novo->getKey())
            ->update(['replaced_at' => now(), 'certificate_encrypted' => null, 'password_encrypted' => null]);

        $connection = SerproConnection::sole();
        $this->assertSame($novo->getKey(), $connection->contractingCertificate()?->getKey());
    }

    public function test_usar_o_ecnpj_sem_certificado_na_conta_1_e_recusado(): void
    {
        Account::factory()->create();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->putJson('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'use_account_certificate' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        $this->assertDatabaseCount('serpro_connections', 0);
    }

    public function test_arquivo_e_vinculo_juntos_sao_recusados(): void
    {
        ['file' => $file] = $this->pfx();
        AccountCertificate::factory()->create(['account_id' => Account::factory()->create()->getKey()]);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $file,
                'password' => self::SENHA,
                'use_account_certificate' => '1',
            ], $this->jsonHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        $this->assertDatabaseCount('serpro_connections', 0);
    }

    public function test_enviar_outro_arquivo_encerra_o_vinculo(): void
    {
        // Uma credencial que reusava o e-CNPJ da conta 1 passa a guardar o
        // próprio arquivo quando o super_admin escolhe "enviar outro": o
        // vínculo morre e as colunas de certificado voltam a ser a fonte.
        $account = Account::factory()->create();
        AccountCertificate::factory()->create(['account_id' => $account->getKey()]);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'use_account_certificate' => '1',
            ], $this->jsonHeaders())
            ->assertOk();

        ['bytes' => $bytes, 'file' => $file] = $this->pfx();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'certificate' => $file,
                'password' => self::SENHA,
            ], $this->jsonHeaders())
            ->assertOk();

        $connection = SerproConnection::sole();
        $this->assertNull($connection->contracting_account_id);
        $this->assertSame($bytes, $connection->certificateBytes());
        $this->assertSame(self::CNPJ, $connection->contratante_numero);
    }

    /**
     * @return array<string, string>
     */
    /**
     * A pista como a resource a monta, sem passar pela requisição: a guarda é
     * do método privado, e o que se quer verificar é o valor que ele decide.
     */
    private function pistaDe(SerproConnection $connection): ?string
    {
        $pista = (new SerproConnectionResource($connection))->toArray(request())['consumer_key_hint'] ?? null;

        return $pista === null ? null : (string) $pista;
    }

    private function jsonHeaders(): array
    {
        return ['Accept' => 'application/json'];
    }

    /**
     * A primeira falha que a camada de serviço levanta, sem passar por nenhuma
     * rede: `preventStrayRequests` acima transforma qualquer ida ao provedor em
     * exceção, então o que este helper devolve é a falha local e nada mais.
     */
    private function falhaDoGateway(): SerproException
    {
        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000107',
                '33683111000875',
            );
        } catch (SerproException $exception) {
            return $exception;
        }

        $this->fail('A camada de serviço deveria ter levantado SerproException.');
    }

    /**
     * Pasta efêmera do PFX sem gravação, pelo mesmo caminho do teste do
     * materializador: o `put()` que devolve `false` é o que ele trata como
     * falha, e nenhum teste pode fingir uma permissão de disco diferente da que a
     * máquina de teste tem.
     */
    private function tempDirIngravavel(): void
    {
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('path')->andReturnUsing(
            fn (string $path = ''): string => storage_path('app/private/'.ltrim($path, '/')),
        );
        $mock->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($mock);
    }

    /**
     * O upload da credencial da plataforma precisa **não** usar
     * `UploadedFile::fake()` para provar que o arquivo é aceito.
     *
     * O `mimes` do Laravel não compara tipo de mídia: ele chama
     * `guessExtension()`, que pergunta ao host o que ele adivinha do
     * **conteúdo** do arquivo. E o `UploadedFile::fake()` deriva o MIME do
     * **nome** (`Illuminate\Http\Testing\File::getMimeType()` →
     * `MimeType::from($this->name)`), nunca do conteúdo — então um fake chamado
     * `plataforma.pfx` se apresenta como `application/pkcs12` e **passa** em
     * `mimes:pfx,p12` mesmo num host cuja libmagic não conhece PKCS#12.
     *
     * Foi por isso que este endpoint aceitou, na suíte inteira, um `mimes` que
     * recusa o certificado de verdade: os dezoito testes deste arquivo sobem um
     * PFX de verdade, mas nenhum deles por um caminho que faça o host adivinhar.
     *
     * Este caso usa um `UploadedFile` **real**, sobre um arquivo real em disco,
     * que é o que o PHP-FPM recebe de um upload de verdade. Medido neste host
     * (`file-5.45`, cuja base mágica não tem PKCS#12):
     *
     *     file --mime-type real.p12  ->  application/octet-stream
     *     guessExtension()          ->  bin
     *     mimes:pfx,p12              ->  RECUSA
     *     extensions:pfx,p12         ->  ACEITA
     *
     * A asymetria é a do host, e não do formato: um libmagic que conhece PKCS#12
     * (`MimeTypes` mapeia `'application/pkcs12' => ['p12','pfx']`) adivinha
     * `p12` e o `mimes` passa. `extensions` não depende de nenhum dos dois — lê
     * `getClientOriginalExtension()`, o nome que o cliente mandou — e por isso é
     * a regra determinística, que é o que este teste exige.
     */
    public function test_o_upload_da_credencial_aceita_um_p12_de_verdade_como_arquivo_real(): void
    {
        ['bytes' => $bytes, 'file' => $real] = $this->pfxReal(self::CNPJ);

        // O arquivo é real e está em disco — não é um `File` de teste, e é isso
        // que faz `guessExtension()` consultar a libmagic do host.
        $this->assertNotInstanceOf(File::class, $real);
        $this->assertFileExists($real->getPathname());

        $super = User::factory()->create(['is_super_admin' => true]);

        $resposta = $this->actingAs($super, 'sanctum')
            ->put('/api/serpro/connection', [
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret' => self::SEGREDO,
                'certificate' => $real,
                'password' => self::SENHA,
            ], $this->jsonHeaders());

        $resposta->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.contracting_document', self::CNPJ);

        // E a credencial gravada é a do arquivo que subiu: a prova de que a
        // requisição não só passou, como que passou com o conteúdo certo.
        $this->assertSame(self::CNPJ, SerproConnection::sole()->contratante_numero);
        $this->assertSame($bytes, SerproConnection::sole()->certificateBytes());
    }

    /**
     * Um PKCS#12 de verdade, sobre um **arquivo real** em disco.
     *
     * O par contra o `pfx()` de cima é o `UploadedFile`: o de lá é um
     * `Illuminate\Http\Testing\File`, que reporta o MIME pelo nome, e o daqui é
     * um `Illuminate\Http\UploadedFile` sobre um caminho de verdade, que obriga o
     * Symfony a perguntar à libmagic. O caminho é registrado em
     * `self::$arquivosTemporarios`, e é de lá que `tearDownAfterClass()` apaga o
     * arquivo.
     *
     * @return array{bytes: string, file: UploadedFile}
     */
    private function pfxReal(string $document): array
    {
        $config = $this->opensslConfig();
        $subject = ['CN' => 'SERPRO PLATAFORMA LTDA:'.$document, 'serialNumber' => $document];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new($subject, $key, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $bytes = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $bytes, $key, self::SENHA));

        // `tempnam` e não um nome montado: o caminho tem de ser único para que
        // dois testes em paralelo não sobrescrevam um ao outro, e tem de caber
        // no limite do sistema de arquivos. O caminho é **registrado**, e é o
        // registro que a limpeza percorre: apagar por padrão de nome desfaria na
        // limpeza a corrida que o `tempnam` acabou de evitar, porque o `glob`
        // alcançaria o PFX da outra execução também.
        $caminho = tempnam(sys_get_temp_dir(), 'serpro-p12-');
        $this->assertNotFalse($caminho);
        file_put_contents($caminho, $bytes);
        self::$arquivosTemporarios[] = $caminho;

        return [
            'bytes' => $bytes,
            'file' => new UploadedFile($caminho, 'plataforma.p12', null, null, true),
        ];
    }

    /**
     * @param  array<int, string>  $keys
     * @return list<string>
     */
    private function sortedKeys(mixed $value): array
    {
        $this->assertIsArray($value);

        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, string>|null  $subject
     * @return array{bytes: string, file: UploadedFile}
     */
    private function pfx(?array $subject = null, int $days = 365): array
    {
        // `.pfx` é ignorado pelo git: o certificado é gerado em runtime, como
        // em `ClientCertificateTest`, e nunca versionado.
        $subject ??= ['CN' => 'SERPRO PLATAFORMA LTDA:'.self::CNPJ, 'serialNumber' => self::CNPJ];
        $config = $this->opensslConfig();

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new($subject, $key, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, $days, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, self::SENHA));

        return ['bytes' => $pfx, 'file' => UploadedFile::fake()->createWithContent('plataforma.pfx', $pfx)];
    }

    /**
     * @return array{config?: string}
     */
    private function opensslConfig(): array
    {
        return file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
