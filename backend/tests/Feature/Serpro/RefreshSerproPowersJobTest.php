<?php

namespace Tests\Feature\Serpro;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Jobs\RefreshSerproPowersJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Services\SerproClientLock;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O oráculo fora da execução: um job por cliente, com `account_id` explícito,
 * o lock do contribuinte e a chamada auditada com `run_id` nulo. A janela de
 * vinte horas é o que impede a rotina de cobrar duas vezes no mesmo dia.
 */
class RefreshSerproPowersJobTest extends TestCase
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

    public function test_o_job_consulta_o_oraculo_grava_na_conta_do_cliente_e_registra_a_chamada(): void
    {
        [$account, $client] = $this->cenarioChamavel();
        $outra = Account::factory()->create();
        // O tenant residual de um worker é o do job anterior: a escrita e a
        // auditoria têm de sair pela conta que o job carrega.
        resolve(CurrentTenant::class)->accountId = $outra->getKey();

        Http::fake($this->fakesDoProvedor());

        (new RefreshSerproPowersJob($account->getKey(), $client->getKey()))->handle();

        // As famílias que o provedor concedeu caem na conta do cliente — a do
        // parâmetro — e não na que sobrou no singleton.
        $autorizacoes = SerproClientAuthorization::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $account->getKey())
            ->where('client_id', $client->getKey())
            ->orderBy('family')
            ->get();

        $this->assertSame(['00006', '00050'], $autorizacoes->pluck('family')->all());
        $this->assertSame(0, SerproClientAuthorization::query()
            ->withoutGlobalScope('account')->where('account_id', $outra->getKey())->count());

        // A chamada entra na auditoria de cobrança como a de uma execução, e
        // `run_id` nulo é o que a distingue: esta saiu fora de execução. A
        // consulta tira o escopo de conta porque o singleton ainda aponta
        // para a conta residual de propósito.
        $chamada = SerproCall::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $account->getKey())
            ->sole();
        $this->assertNull($chamada->run_id);
        $this->assertSame('OBTERPROCURACAO41', $chamada->id_servico);
        $this->assertSame($client->getKey(), $chamada->client_id);

        // E o singleton volta como estava: o job não deixa o tenant dele para o próximo.
        $this->assertSame($outra->getKey(), resolve(CurrentTenant::class)->accountId);
    }

    public function test_conta_nao_habilitada_nao_chama_nada(): void
    {
        [$account, $client] = $this->cenarioChamavel();
        $account->forceFill(['settings' => ['serpro_enabled' => false]])->save();

        (new RefreshSerproPowersJob($account->getKey(), $client->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, SerproCall::count());
    }

    public function test_sem_termo_ou_sem_certificado_do_escritorio_nao_chama_nada(): void
    {
        [$account, $client] = $this->cenarioChamavel();
        SerproAuthorizationTerm::query()->where('account_id', $account->getKey())->delete();

        (new RefreshSerproPowersJob($account->getKey(), $client->getKey()))->handle();

        Http::assertNothingSent();

        [$segundaConta, $segundoCliente] = $this->cenarioChamavel();
        AccountCertificate::query()->where('account_id', $segundaConta->getKey())->delete();

        (new RefreshSerproPowersJob($segundaConta->getKey(), $segundoCliente->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, SerproCall::count());
    }

    public function test_pessoa_fisica_nao_chama_nada(): void
    {
        [$account] = $this->cenarioChamavel();
        $pf = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        (new RefreshSerproPowersJob($account->getKey(), $pf->getKey()))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, SerproCall::count());
    }

    public function test_verificado_ha_menos_de_vinte_horas_e_pulado(): void
    {
        [$account, $client] = $this->cenarioChamavel();
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00006',
            'state' => SerproPowerOfAttorneyState::Established,
            'verified_at' => now()->subHours(19),
        ]);

        (new RefreshSerproPowersJob($account->getKey(), $client->getKey()))->handle();

        Http::assertNothingSent();

        // Com vinte horas e um minuto a consulta acontece de novo: a janela é o
        // que torna a rotina diária barata, não o que a impede de existir.
        SerproClientAuthorization::query()
            ->where('client_id', $client->getKey())
            ->update(['verified_at' => now()->subHours(20)->subMinute()]);

        Http::fake($this->fakesDoProvedor());

        (new RefreshSerproPowersJob($account->getKey(), $client->getKey()))->handle();

        Http::assertSentCount(1);
    }

    public function test_o_force_da_troca_de_documento_pula_a_janela(): void
    {
        [$account, $client] = $this->cenarioChamavel();
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00006',
            'state' => SerproPowerOfAttorneyState::Established,
            'verified_at' => now()->subHour(),
        ]);

        Http::fake($this->fakesDoProvedor());

        (new RefreshSerproPowersJob($account->getKey(), $client->getKey(), force: true))->handle();

        // O documento novo pode ter outorga que o antigo não tinha: o gatilho
        // de troca ignora a janela porque a resposta anterior era de outro CNPJ.
        Http::assertSentCount(1);
    }

    public function test_o_lock_do_cliente_tomado_devolve_o_job_a_fila(): void
    {
        [$account, $client] = $this->cenarioChamavel();

        $ocupado = resolve(SerproClientLock::class)->acquire($account->getKey(), (string) $client->tax_id);
        $this->assertNotNull($ocupado);

        $job = (new RefreshSerproPowersJob($account->getKey(), $client->getKey()))->withFakeQueueInteractions();
        $job->handle();

        // O SyncSerproClientJob do mesmo cliente está trabalhando: a consulta
        // de procuração espera o lock em vez de correr em paralelo com ele.
        $job->assertReleased();
        Http::assertNothingSent();
        $this->assertSame(0, SerproCall::count());

        $ocupado->release();
    }

    /**
     * @return array{0: Account, 1: Client}
     */
    private function cenarioChamavel(): array
    {
        // `withoutEvents` porque o observer da conta semeia departamentos e
        // assinatura — que este teste não usa — e hoje a semeadura quebra em
        // conta sem membro autenticado, um defeito do cruzamento com a
        // migration de `departments` em andamento, e não do oráculo.
        $account = Account::withoutEvents(fn (): Account => Account::factory()->create(['settings' => ['serpro_enabled' => true]]));

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
            'token_expires_at' => CarbonImmutable::parse('2026-09-29'),
        ]);

        AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => '12345678000195',
        ]);

        Cache::put('serpro:token-pair', new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        return [$account, $client];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakesDoProvedor(): array
    {
        return [
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => Http::response([
                'status' => 200,
                'dados' => '[{"dtexpiracao":"20270101","nrsistemas":"2","sistemas":["Caixa Postal - Mensagens","Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico"]}]',
                'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Requisição efetuada com sucesso']],
                'responseId' => 'resp-1',
            ]),
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

        $certificate = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($certificate);

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'senha'));

        return $pfx;
    }
}
