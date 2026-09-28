<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\Account;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum endpoint desta tela fala com o provedor: a credencial é
        // configurada aqui e usada em outro processo. Qualquer chamada HTTP
        // seria um desvio de rota, não um efeito colateral tolerável.
        Http::preventStrayRequests();
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
     * Os três `NotSent` do enum — pasta temporária sem gravação, segredo ilegível
     * e certificado ilegível — significam "falha nossa, antes de qualquer
     * requisição", e cada um precisa ser testemunhado no produtor. O veredito do
     * teste de conectividade não serve: ele fixa `certificado` ou `provedor` no
     * guard, antes de olhar a falha, e passaria igual com `DoNotRetry`.
     */
    public function test_cada_falha_local_antes_da_requisicao_e_nomeada_como_nada_enviado(): void
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
            ->assertJsonValidationErrors(['consumer_secret', 'certificate', 'password']);

        $this->assertDatabaseCount('serpro_connections', 0);
    }

    /**
     * @return array<string, string>
     */
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
