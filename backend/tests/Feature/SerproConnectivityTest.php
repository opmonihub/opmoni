<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\ClientEcacPowerOfAttorney;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\SerproConnectivity;
use App\Services\SerproTokenPair;
use App\Services\SerproTokenProvider;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class SerproConnectivityTest extends TestCase
{
    use RefreshDatabase;

    /** O CNPJ que o certificado de teste carrega; é o mesmo do factory. */
    private const CNPJ = '12345678000195';

    private const SENHA = 'senha-do-pfx';

    private const SEGREDO = 'segredo-da-plataforma';

    /** Só a autenticação é esperada: o gateway nunca entra neste teste. */
    private const AUTHENTICATION = 'autenticacao.sapi.serpro.gov.br/*';

    /**
     * Toda tabela que carrega dado de cliente. A carteira montada em `setUp()`
     * é o que dá sentido à exigência de não tocar em nada: um teste que passa
     * por não ter cliente para tocar não prova nada.
     *
     * @var list<string>
     */
    private const CLIENT_TABLES = [
        'client_certificates',
        'client_ecac_powers_of_attorney',
        'client_saved_filters',
        'client_tag',
        'clients',
        'fiscal_cursors',
        'fiscal_documents',
        'serpro_monitorings',
    ];

    /** @var array<string, list<string>> */
    private array $carteira = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum desvio de rota: uma chamada que não for a autenticação do
        // provedor estoura aqui em vez de passar por um fake largo.
        Http::preventStrayRequests();

        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->create(['client_id' => $client->getKey()]);
        ClientEcacPowerOfAttorney::factory()->create(['client_id' => $client->getKey()]);

        $this->carteira = $this->carteira();

        // Retrato vazio não prova nada: se a carteira de cima sumir do `setUp()`,
        // a comparação do `tearDown()` continua passando e o requisito fica sem
        // testigo.
        $this->assertNotEmpty($this->carteira['clients'], 'A carteira do setUp é o que dá sentido à comparação.');
        $this->assertNotEmpty($this->carteira['client_certificates']);
        $this->assertNotEmpty($this->carteira['client_ecac_powers_of_attorney']);
    }

    protected function tearDown(): void
    {
        // A comparação vive no `tearDown()` para valer mesmo quando o teste
        // falha antes dela; o rollback do `RefreshDatabase` acontece depois, em
        // `parent::tearDown()`. O retrato vazio é `setUp()` que não chegou ao
        // fim — aí a falha que importa é a dele, e compará-lo contra um retrato
        // vazio só acrescentaria um segundo erro que mascara o primeiro.
        if ($this->carteira !== []) {
            $this->assertSame(
                $this->carteira,
                $this->carteira(),
                'O teste de conectividade não pode criar, modificar nem apagar dado de cliente.',
            );
        }

        parent::tearDown();
    }

    /**
     * A taxonomia precisa declarar o destino de **todo** desfecho, e o teste é o
     * que a mantém declarada: um caso novo que não apareça na lista abaixo cai no
     * `default` e vira `credencial` — "reveja a chave, o segredo e o certificado"
     * — quando a verdade é outra, e nenhuma tela descobre que mandou o operador
     * para o lado errado.
     *
     * `Throttled` é limite do provedor, e limite não é recusa do que foi
     * enviado: a ação é esperar, exatamente como em `Upstream`. Deixá-lo no
     * `default` puniria duas vezes quem está apenas com o servidor ocupado —
     * com a frase errada e com o tom de erro, que
     * `frontend/app/utils/serproConnectivityPresentation.ts` reserva para a
     * credencial.
     *
     * A lista carrega o `status` porque `DoNotRetry` tem dois destinos legítimos
     * e o que os separa é ele: `0` é conferência local de certificado — identidade
     * re-conferida e materializador sem certificado — e um `status` real é recusa do
     * que o provedor viu. O `status` zero nunca é o de uma recusa:
     * `SerproTokenProvider::refusal()` sempre repassa o da resposta. A credencial
     * ausente é `status` zero e **não** é certificado, mas ela não chega aqui: a
     * resposta com a releitura está em `test_credencial_apagada_no_meio_da_verificacao_e_configuracao_e_nao_certificado`.
     */
    public function test_taxonomia_de_elementos_declara_destino_para_todo_desfecho_do_enum(): void
    {
        $destinos = [
            // Quem não deu conta, quem não sabe se deu, quem não mandou nada e
            // quem limitou: esperar, e não redigitar nada.
            'provedor' => [
                [SerproFailure::Upstream, 503],
                [SerproFailure::Throttled, 429],
                [SerproFailure::Indeterminate, 504],
                [SerproFailure::NotSent, 0],
            ],
            // Conferência local que o provedor nunca chegou a ver: certificado
            // vencido, documento divergente, identidade ilegível, certificado
            // ausente. Troque-se o certificado, não a credencial.
            'certificado' => [
                [SerproFailure::DoNotRetry, 0],
            ],
            // Recusa do que foi enviado: corrigir a credencial.
            'credencial' => [
                [SerproFailure::Reauthenticate, 401],
                [SerproFailure::ResubmitTerm, 403],
                [SerproFailure::DoNotRetry, 400],
                [SerproFailure::DoNotRetry, 401],
                // `Success` nunca chega: `check()` só traduz a exceção de uma
                // falha. O destino está declarado para o `default` não ser um
                // buraco em silêncio — e para o dia em que algum chegar, o teste
                // estar esperando por uma decisão, e não por um accidento.
                [SerproFailure::Success, 0],
            ],
        ];

        $declarados = array_merge(...array_values($destinos));

        // A lista é o contrato: um `case` novo que não apareça aqui precisa
        // falhar, e um `case` declarado duas vezes não pode tapar outro que
        // sumiu. A comparação é de conjuntos — ordenar e comparar os valores
        // únicos, porque contar não distingue "todos declarados" de "um
        // declarado no lugar de outro".
        $this->assertEqualsCanonicalizing(
            array_map(fn (SerproFailure $falha): string => $falha->value, SerproFailure::cases()),
            array_values(array_unique(array_map(
                fn (array $declarado): string => $declarado[0]->value,
                $declarados,
            ))),
            'Todo desfecho do enum precisa de destino declarado na taxonomia.',
        );

        foreach ($destinos as $elemento => $falhas) {
            foreach ($falhas as [$falha, $status]) {
                $this->assertSame(
                    $elemento,
                    SerproConnectivity::elementFor($falha, $status),
                    "{$falha->value} com status {$status} não pode virar o outro elemento.",
                );
            }
        }
    }

    /**
     * `DoNotRetry` é o caso que os dois lados precisam distinguir, e o que os
     * separa não é o rótulo: é o `status`. `0` é uma conferência que o
     * provedor nunca viu — o conserto é o certificado. Um `status` real é
     * recusa do que o provedor recebeu — o conserto é a credencial.
     */
    public function test_do_not_retry_separa_conferencia_local_de_recusa_pelo_status(): void
    {
        $this->assertSame('certificado', SerproConnectivity::elementFor(SerproFailure::DoNotRetry, 0));
        $this->assertSame('credencial', SerproConnectivity::elementFor(SerproFailure::DoNotRetry, 400));
        $this->assertSame('credencial', SerproConnectivity::elementFor(SerproFailure::DoNotRetry, 401));
        $this->assertSame('credencial', SerproConnectivity::elementFor(SerproFailure::DoNotRetry, 403));
    }

    public function test_certificado_que_deriva_no_meio_da_verificacao_e_certificado_e_nao_credencial(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        // A única janela em que a falha de identidade chega à taxonomia: os
        // guard acima conferiram o certificado, e ele ficou vencido entre essa
        // conferência e a releitura que `authenticate()` faz logo depois. O
        // operador que receber `credencial` aqui é mandado refazer chave, segredo
        // e certificado — e o certificado não é o culpado: ele é o que mudou.
        $deriva = false;

        DB::listen(function (QueryExecuted $query) use (&$deriva): void {
            if ($deriva || ! str_contains($query->sql, 'from "serpro_connections"')) {
                return;
            }

            $deriva = true;

            // A releitura que este `listen` alcança é a de `authenticate()`: o
            // evento dispara depois do `select` e antes de a `SerproConnection`
            // ser montada, de modo que a credencial já conferida por `check()`
            // continua válida e a seguinte já nasce vencida.
            DB::table('serpro_connections')->update([
                'certificate_valid_until' => now()->subDay(),
            ]);
        });

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'certificado')
            ->assertJsonPath(
                'data.message',
                'O certificado do contratante não está configurado, ou não serve para esta credencial.',
            );

        $this->assertTrue($deriva, 'O certificado precisa ter derivado no meio da verificação.');

        // Nenhuma autenticação foi gasta descobrindo que o certificado mudou: a
        // identidade é conferida antes de qualquer requisição.
        Http::assertNothingSent();
    }

    public function test_credencial_apagada_no_meio_da_verificacao_e_configuracao_e_nao_certificado(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        // A outra ponta da mesma janela. Aqui a linha é apagada entre a
        // conferência do começo e a releitura que a autenticação faz, e o provedor
        // de token responde "não configurada" — que é um `DoNotRetry` de `status`
        // zero, a mesma assinatura da falha de certificado. Ler só o `status`
        // mandaria o operador trocar o certificado de uma credencial que não
        // existe mais.
        $apagada = false;

        DB::listen(function (QueryExecuted $query) use (&$apagada): void {
            if ($apagada || ! str_contains($query->sql, 'from "serpro_connections"')) {
                return;
            }

            $apagada = true;
            DB::table('serpro_connections')->delete();
        });

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'configuracao')
            ->assertJsonPath(
                'data.message',
                'A credencial do Integra Contador não está configurada: faltam a chave de integração ou o segredo.',
            );

        $this->assertTrue($apagada, 'A credencial precisa ter sido apagada no meio da verificação.');

        Http::assertNothingSent();
    }

    public function test_sem_conexao_retorna_configuracao_sem_chamar_provedor(): void
    {
        $antes = now();
        $response = $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
            ->postJson('/api/serpro/connectivity');
        $depois = now();

        $response->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'configuracao')
            ->assertJsonPath(
                'data.message',
                'A credencial do Integra Contador não está configurada: faltam a chave de integração ou o segredo.',
            );

        $this->assertCheckedAtIsNow($response, $antes, $depois);

        Http::assertNothingSent();
    }

    public function test_credencial_incompleta_e_configuracao_e_nao_credencial_recusada(): void
    {
        // Chave vazia: o que falta é configuração local. Um operador que lesse
        // "credencial" aqui iria caçar no SERPRO o que está errado na própria
        // tela. O segredo não tem este teste porque a coluna é `NOT NULL` — não
        // existe linha sem segredo guardado, e a entrada que produziria uma nem
        // chega a ser gravada.
        SerproConnection::factory()->create(['consumer_key' => '']);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'configuracao');

        Http::assertNothingSent();
    }

    public function test_certificado_ausente_e_certificado_sem_chamar_provedor(): void
    {
        // A linha da plataforma sem PFX: nada a materializar e nada a
        // autenticar. O conserto é gravar o certificado, e não reler a credencial
        // no SERPRO.
        SerproConnection::factory()->create(['certificate_encrypted' => null]);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'certificado');

        Http::assertNothingSent();
    }

    public function test_certificado_vencido_e_certificado_sem_chamar_provedor(): void
    {
        // Certificado guardado e vencido é o mesmo conserto — trocar o
        // certificado —, e não "credencial recusada". O PFX aqui é um de verdade:
        // com a coluna do certificado vazia o veredito viria da ausência dele e
        // não da validade, e o teste não estaria exercitando o que diz
        // exercitar.
        $this->connection(['certificate_valid_until' => now()->subDay()]);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'certificado');

        Http::assertNothingSent();
    }

    public function test_certificado_de_outro_contratante_e_certificado_sem_chamar_provedor(): void
    {
        // O `contratante_numero` guardado não é o do PFX guardado: estado que
        // só uma escrita fora do manager produz, e que o provedor responderia
        // com um `403` indistinguível de senha errada.
        $this->connection(['contratante_numero' => '27865757000102']);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'certificado');

        Http::assertNothingSent();
    }

    public function test_credencial_recusada_com_401_e_credencial(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response(['message' => 'Token recusado.'], 401)]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'credencial')
            ->assertJsonPath(
                'data.message',
                'A credencial configurada não pôde ser usada; reveja a chave de integração, o segredo e o certificado gravados.',
            );

        Http::assertSentCount(1);
    }

    public function test_autenticacao_limitada_e_provedor_e_nao_credencial(): void
    {
        // Um `429` real do provedor de token é o único jeito de chegar ao
        // desfecho `provedor` por limite, e ele precisa chegar: tratado como
        // recusa de credencial, o operador era mandado refazer chave, segredo e
        // certificado por causa de um serviço ocupado.
        Http::fake([self::AUTHENTICATION => Http::response(['message' => 'Limite de requisições.'], 429)]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'provedor')
            ->assertJsonPath(
                'data.message',
                'A verificação não pôde ser concluída: o serviço de autenticação do Integra Contador ou a máquina que o executa não respondeu como esperado.',
            );

        Http::assertSentCount(1);
    }

    public function test_recusa_que_nao_e_401_tambem_e_credencial(): void
    {
        // A autenticação do SERPRO recusa credencial com `400` ("Não foi
        // possível identificar um certificado digital válido") tanto quanto
        // com `401`. As duas são recusa do que foi enviado, e o provedor não
        // autentica uma credencial que ele não reconheceu: tratar as duas como
        // indisponibilidade mandaria o operador esperar por um serviço que está
        // de pé e respondendo.
        Http::fake([self::AUTHENTICATION => Http::response(['message' => 'Certificado inválido.'], 400)]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'credencial');

        Http::assertSentCount(1);
    }

    public function test_erro_do_servidor_e_provedor(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response(['message' => 'Erro interno.'], 503)]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'provedor')
            ->assertJsonPath(
                'data.message',
                'A verificação não pôde ser concluída: o serviço de autenticação do Integra Contador ou a máquina que o executa não respondeu como esperado.',
            );

        Http::assertSentCount(1);
    }

    public function test_provedor_inalcancavel_e_provedor(): void
    {
        Http::fake(Http::failedConnection('connection refused'));
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'provedor');

        Http::assertSentCount(1);
    }

    public function test_resposta_sem_o_token_de_autorizacao_e_provedor_e_nao_segue_para_o_gateway(): void
    {
        // O par incompleto é defeito da resposta do provedor, não da
        // credencial: sem o `jwt_token` nenhuma chamada de serviço é possível,
        // e o teste de conectividade não pode virar atalho para o gateway.
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
        ])]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'provedor');

        // Uma única chamada, e para a autenticação: `preventStrayRequests`
        // transformaria uma ida ao gateway em exceção, mas a contagem diz o
        // que a exceção sozinha não diria.
        Http::assertSentCount(1);
    }

    public function test_certificado_guardado_ilegivel_e_certificado_e_nao_500(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $connection = $this->connection();

        // `APP_KEY` girada, coluna truncada, linha restaurada de outro ambiente:
        // o cifrado guardado não abre mais. Um `500` é a resposta menos
        // informativa possível a "por que a minha credencial está quebrada?" — e
        // o conserto aqui é recadastrar o certificado, não mexer na chave de
        // integração.
        $connection->forceFill(['certificate_encrypted' => 'cifrado-que-nao-abre'])->save();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'certificado');

        // A senha do PFX é o outro cifrado aberto na mesma leitura, e falha do
        // mesmo jeito: os dois precisam de veredito, e não de exceção.
        $connection->forceFill([
            'certificate_encrypted' => Crypt::encryptString($this->pfx()),
            'certificate_password_encrypted' => 'cifrado-que-nao-abre',
        ])->save();

        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'certificado');

        Http::assertNothingSent();
    }

    public function test_diretorio_temporario_ingravavel_e_provedor_e_nao_certificado(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        // O `put()` devolvendo `false` é a pasta efêmera do PFX recusando a
        // gravação — o FPM roda como root, o worker como `www-data`, e a imagem
        // de produção não roda `storage:link`. A credencial está inteira e
        // ninguém provou nada sobre ela: dizer `certificado` aqui mandaria o
        // operador trocar um certificado bom.
        $this->tempDirIngravavel();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.failed_element', 'provedor');

        // A gravação falha antes de qualquer requisição, e nenhuma tentativa
        // chega ao gateway.
        Http::assertNothingSent();
    }

    public function test_segredo_guardado_ilegivel_e_credencial_e_nao_500(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $connection = $this->connection();

        // Chave de aplicação trocada, coluna truncada, restauração de outro
        // ambiente: o cifrado guardado não abre. Um `500` é a resposta menos
        // informativa possível a "por que a minha credencial está quebrada?", e
        // este endpoint existe para nomear o que quebrou.
        $connection->forceFill(['consumer_secret_encrypted' => 'cifrado-que-nao-abre'])->save();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.failed_element', 'credencial')
            ->assertJsonPath(
                'data.message',
                'A credencial configurada não pôde ser usada; reveja a chave de integração, o segredo e o certificado gravados.',
            );

        // O segredo nunca chegou a ser enviado: a falha é de leitura local.
        Http::assertNothingSent();
    }

    public function test_autenticacao_valida_responde_ok_sem_chamar_o_gateway(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'token_type' => 'Bearer',
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        $antes = now();
        $this->superAdmin();
        $response = $this->postJson('/api/serpro/connectivity');
        $depois = now();

        $response->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.failed_element', null)
            ->assertJsonPath('data.message', null);

        $this->assertCheckedAtIsNow($response, $antes, $depois);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://autenticacao.sapi.serpro.gov.br/');
        });
    }

    public function test_o_par_recem_emitido_fica_em_cache_para_a_execucao_seguinte(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $pair = Cache::get('serpro:token-pair');

        $this->assertInstanceOf(SerproTokenPair::class, $pair);
        $this->assertSame('jwt-1', $pair->jwtToken());

        // Testar a conexão não pode custar uma autenticação à próxima execução
        // de verdade: o par emitido aqui é o que a sincronização reaproveita.
        resolve(SerproTokenProvider::class)->pair();

        Http::assertSentCount(1);
    }

    public function test_o_par_em_cache_nao_e_aceito_como_teste_de_conectividade(): void
    {
        Http::fake([self::AUTHENTICATION => Http::response([
            'expires_in' => 2008,
            'access_token' => 'access-1',
            'jwt_token' => 'jwt-1',
        ])]);
        $this->connection();

        // Um par em cache é o resto de uma autenticação que já deu certo: quem
        // pergunta "está funcionando agora?" precisa de uma resposta de agora,
        // e não do token de meia hora atrás.
        Cache::put('serpro:token-pair', new SerproTokenPair('access-antigo', 'jwt-antigo', 2008), 600);

        $this->superAdmin();
        $this->postJson('/api/serpro/connectivity')
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        Http::assertSentCount(1);
    }

    public function test_resposta_nao_traz_texto_do_provedor_nem_segredo(): void
    {
        // O provedor pode devolver qualquer coisa num erro, inclusive o eco do
        // que foi enviado. A resposta nomeia o que recaiu sobre quem e nunca
        // repassa esse texto.
        $textoDoProvedor = 'client_credentials 5000012345 / '.self::SEGREDO.' / '.self::SENHA;

        Http::fake([self::AUTHENTICATION => Http::response([
            'message' => $textoDoProvedor,
            'detail' => self::SEGREDO,
        ], 401)]);
        $this->connection();

        $this->superAdmin();
        $response = $this->postJson('/api/serpro/connectivity');
        $content = (string) $response->getContent();

        $response->assertOk()
            ->assertJsonPath('data.failed_element', 'credencial');

        // O corpo tem exatamente as quatro chaves do contrato: nenhuma chave a
        // mais por onde um texto do provedor ou um segredo pudessem vazar, e
        // nenhuma a menos que a tela precise para dizer o que aconteceu.
        $this->assertSame(
            ['checked_at', 'failed_element', 'message', 'ok'],
            $this->sortedKeys($response->json('data')),
        );

        $this->assertStringNotContainsString($textoDoProvedor, $content);
        $this->assertStringNotContainsString(self::SEGREDO, $content);
        $this->assertStringNotContainsString(self::SENHA, $content);
        $this->assertStringNotContainsString(
            (string) SerproConnection::sole()->getRawOriginal('consumer_secret_encrypted'),
            $content,
        );
    }

    public function test_membro_da_conta_sem_super_admin_recebe_403(): void
    {
        $this->postJson('/api/serpro/connectivity')->assertUnauthorized();

        $this->connection();

        // Admin da conta não é operador da plataforma: a credencial é da
        // plataforma, e `super_admin` é `users.is_super_admin`, não um papel de
        // `account_user.role`. Qualquer um dos três papéis recebe a mesma
        // recusa.
        foreach (['admin', 'operador', 'user'] as $role) {
            $this->actingAs($this->memberOf($role), 'sanctum')
                ->postJson('/api/serpro/connectivity')
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    /**
     * Simula a pasta efêmera do PFX sem gravação, pelo mesmo caminho do teste do
     * materializador: o `put()` que devolve `false` é o que ele trata como
     * falha, e nenhum teste aqui pode fingir uma permissão de disco diferente da
     * que a máquina de teste tem.
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
     * @param  array<string, mixed>  $atributos
     */
    private function connection(array $atributos = []): SerproConnection
    {
        $pfx = $this->pfx();

        return SerproConnection::factory()->create(array_merge([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString(self::SEGREDO),
            'certificate_encrypted' => Crypt::encryptString($pfx),
            'certificate_password_encrypted' => Crypt::encryptString(self::SENHA),
            'contratante_numero' => self::CNPJ,
            'certificate_valid_until' => now()->addYear(),
        ], $atributos));
    }

    private function superAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum');
    }

    private function memberOf(string $role): User
    {
        $account = Account::factory()->create();
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    /** PFX real, com o CNPJ da coluna de contratante, gerado em runtime. */
    private function pfx(): string
    {
        // `.pfx` é ignorado pelo git: o certificado é gerado em runtime, como
        // em `SerproTokenProviderTest`, e nunca versionado.
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(
            ['CN' => 'SERPRO PLATAFORMA LTDA:'.self::CNPJ, 'serialNumber' => self::CNPJ],
            $key,
            array_merge(['digest_alg' => 'sha256'], $config),
        );
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, self::SENHA));

        return $pfx;
    }

    /**
     * `checked_at` precisa ser o instante da verificação, e não um campo
     * qualquer: é dele que a tela tira o "verificado em".
     */
    private function assertCheckedAtIsNow(TestResponse $response, Carbon $antes, Carbon $depois): void
    {
        $checkedAt = $response->json('data.checked_at');

        $this->assertIsString($checkedAt);
        $this->assertTrue(
            Carbon::parse($checkedAt)->betweenIncluded($antes, $depois),
            "A verificação precisa informar quando foi feita; veio de {$checkedAt}.",
        );
    }

    /**
     * Retrato de todas as linhas de cliente, ordenadas para não depender da
     * ordem em que o banco as devolveu.
     *
     * @return array<string, list<string>>
     */
    private function carteira(): array
    {
        $retrato = [];

        foreach (self::CLIENT_TABLES as $tabela) {
            $linhas = array_map(
                fn (object $linha): string => json_encode((array) $linha, JSON_THROW_ON_ERROR),
                DB::table($tabela)->get()->all(),
            );
            sort($linhas);
            $retrato[$tabela] = $linhas;
        }

        return $retrato;
    }
}
