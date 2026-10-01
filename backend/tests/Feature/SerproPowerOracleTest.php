<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Services\SerproPowerOracle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O oráculo de autorização: a única leitura que transforma o que o SERPRO
 * respondeu em `serpro_client_authorizations`. Toda chamada é contra o
 * gateway de mentira — o contrato do serviço é a fixture documental, e
 * nenhum teste aqui chega perto da rede.
 */
class SerproPowerOracleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_as_familias_concedidas_viram_autorizacoes_estabelecidas(): void
    {
        [$account, $client] = $this->escritorioComTermo();
        $this->fakeProvider();

        resolve(SerproPowerOracle::class)->refresh($account->getKey(), $client->getKey());

        // A fixture publica duas famílias — `00006` e `00050` — valendo até
        // 2027-01-01, e as duas entram como linhas estabelecidas da conta
        // certa e do cliente certo.
        $autorizacoes = SerproClientAuthorization::query()
            ->where('account_id', $account->getKey())
            ->where('client_id', $client->getKey())
            ->orderBy('family')
            ->get();

        $this->assertCount(2, $autorizacoes);
        $this->assertSame(['00006', '00050'], $autorizacoes->pluck('family')->all());
        foreach ($autorizacoes as $autorizacao) {
            $this->assertSame(SerproPowerOfAttorneyState::Established, $autorizacao->state);
            $this->assertSame('2027-01-01', $autorizacao->expires_on?->toDateString());
            $this->assertNotNull($autorizacao->verified_at);
        }
    }

    public function test_nome_de_sistema_desconhecido_nao_autoriza(): void
    {
        [$account, $client] = $this->escritorioComTermo();
        $this->fakeProvider($this->envelopeComDados([
            ['dtexpiracao' => '20270101', 'nrsistemas' => '2', 'sistemas' => [
                'Sistema Que O Catalogo Nao Documenta',
                'Caixa Postal - Mensagens',
            ]],
        ]));

        resolve(SerproPowerOracle::class)->refresh($account->getKey(), $client->getKey());

        // Dos dois nomes, só o comprovado virou linha — o desconhecido foi
        // recusado em silêncio, que é o comportamento seguro diante de um
        // vocabulário que o provedor pode reescrever.
        $this->assertDatabaseCount('serpro_client_authorizations', 1);
        $this->assertDatabaseHas('serpro_client_authorizations', [
            'client_id' => $client->getKey(),
            'family' => '00006',
            'state' => 'established',
        ]);
    }

    public function test_outorga_vencida_marca_a_autorizacao_como_expirada(): void
    {
        [$account, $client] = $this->escritorioComTermo();
        $this->fakeProvider($this->envelopeComDados([
            ['dtexpiracao' => '20200101', 'nrsistemas' => '1', 'sistemas' => ['Caixa Postal - Mensagens']],
        ]));

        resolve(SerproPowerOracle::class)->refresh($account->getKey(), $client->getKey());

        $this->assertDatabaseHas('serpro_client_authorizations', [
            'client_id' => $client->getKey(),
            'family' => '00006',
            'state' => 'expired',
            'expires_on' => '2020-01-01 00:00:00',
        ]);
    }

    public function test_resposta_sem_outorga_nao_grava_nada(): void
    {
        [$account, $client] = $this->escritorioComTermo();
        $this->fakeProvider($this->envelopeComDados([]));

        resolve(SerproPowerOracle::class)->refresh($account->getKey(), $client->getKey());

        // O provedor disse "não consta" e a escrita respeita: nenhuma linha
        // nasce, e nenhuma das que existiam é apagada — a que não veio na
        // resposta fica `rejected`, que é a forma de dizer isso sem fingir
        // que a consulta nunca aconteceu.
        $this->assertDatabaseCount('serpro_client_authorizations', 0);
    }

    public function test_familia_que_sumiu_da_resposta_e_marcada_recusada(): void
    {
        [$account, $client] = $this->escritorioComTermo();
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00050',
            'code' => '00050',
            'state' => SerproPowerOfAttorneyState::Established,
        ]);
        $this->fakeProvider($this->envelopeComDados([
            ['dtexpiracao' => '20270101', 'nrsistemas' => '1', 'sistemas' => ['Caixa Postal - Mensagens']],
        ]));

        resolve(SerproPowerOracle::class)->refresh($account->getKey(), $client->getKey());

        // O `00050` que antes constava não veio na resposta: o provedor é a
        // fonte, e a linha fica `rejected` — apagada ela fingiria nunca ter
        // sido consultada.
        $this->assertDatabaseHas('serpro_client_authorizations', [
            'client_id' => $client->getKey(),
            'family' => '00050',
            'state' => 'rejected',
        ]);
        $this->assertDatabaseHas('serpro_client_authorizations', [
            'client_id' => $client->getKey(),
            'family' => '00006',
            'state' => 'established',
        ]);
    }

    public function test_sem_token_de_termo_nenhuma_chamada_sai(): void
    {
        [$account, $client] = $this->escritorioComTermo();
        SerproAuthorizationTerm::query()->where('account_id', $account->getKey())->delete();

        $this->fakeProvider();

        resolve(SerproPowerOracle::class)->refresh($account->getKey(), $client->getKey());

        // Sem token não há chamada autenticada possível, e nenhuma linha é
        // tocada: `pending` por omissão de verificação, não `rejected` por
        // uma resposta que nunca chegou.
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/Consultar'));
        $this->assertDatabaseCount('serpro_client_authorizations', 0);
    }

    public function test_a_leitura_de_um_cliente_nao_toca_na_autorizacao_de_outra_conta(): void
    {
        [$account, $client] = $this->escritorioComTermo();
        $outraConta = Account::factory()->create();
        $alheio = Client::factory()->company()->create(['account_id' => $outraConta->getKey()]);
        SerproClientAuthorization::factory()->create([
            'account_id' => $outraConta->getKey(),
            'client_id' => $alheio->getKey(),
            'family' => '00006',
            'state' => SerproPowerOfAttorneyState::Rejected,
        ]);

        $this->fakeProvider();

        resolve(SerproPowerOracle::class)->refresh($account->getKey(), $client->getKey());

        // O estado da conta alheia é intocado: a linha `rejected` continua
        // `rejected` mesmo com a resposta concedendo `00006` ao cliente
        // desta conta.
        $this->assertDatabaseHas('serpro_client_authorizations', [
            'account_id' => $outraConta->getKey(),
            'client_id' => $alheio->getKey(),
            'family' => '00006',
            'state' => 'rejected',
        ]);
    }

    /**
     * O escritório completo, pronto para a consulta: conta, e-CNPJ corrente,
     * termo com token válido e cliente PJ.
     *
     * @return array{0: Account, 1: Client}
     */
    private function escritorioComTermo(): array
    {
        $this->conexaoDaPlataforma();
        $account = Account::factory()->create();
        AccountCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'document' => '12345678000195',
        ]);

        // O termo no estado que `validToken()` aceita: autenticado, com o
        // documento em vigor e o token vivo. Os campos de segredo e de estado
        // entram por `forceFill`, que é como o manager os escreve.
        SerproAuthorizationTerm::forceCreate([
            'account_id' => $account->getKey(),
            'author_document' => '12345678000195',
            'document_encrypted' => Crypt::encryptString('<termo/>'),
            'document_expires_on' => today()->addMonth(),
            'signed_at' => now(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('token-do-termo'),
            'token_expires_at' => now()->addDay(),
        ]);

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '99999999999999',
        ]);

        return [$account, $client];
    }

    /**
     * A credencial de plataforma: o `SerproClient` a exige antes de qualquer
     * rede, e o `assertIdentity` compara o CNPJ do PFX com a coluna de
     * contratante — por isso o PFX é real e gerado em runtime, como nos
     * testes do `SerproClient`.
     */
    private function conexaoDaPlataforma(): void
    {
        $pfx = $this->plataformaPfx();

        SerproConnection::factory()->create([
            'certificate_encrypted' => Crypt::encryptString($pfx),
            'certificate_password_encrypted' => Crypt::encryptString('senha'),
            'contratante_numero' => '12345678000195',
            'contratante_tipo' => 2,
            'certificate_valid_until' => now()->addYear(),
        ]);
    }

    private function plataformaPfx(): string
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

    /**
     * O gateway respondendo o envelope da fixture, ou a fixture com o
     * `dados` trocado quando o teste precisa de outra lista de sistemas. A
     * autenticação vem faked junto porque o par de tokens é lido antes do
     * POST.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function fakeProvider(?array $payload = null): void
    {
        $payload ??= $this->payloadDaFixture();

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            'gateway.apiserpro.serpro.gov.br/*' => Http::response($payload),
        ]);
    }

    /**
     * O envelope da fixture com o `dados` trocado — a resposta do serviço é
     * a string de um array de procurações, na camada de escape que o
     * provedor usa.
     *
     * @param  list<array{dtexpiracao: string, nrsistemas: string, sistemas: list<string>}>  $procuracoes
     * @return array<string, mixed>
     */
    private function envelopeComDados(array $procuracoes): array
    {
        $payload = $this->payloadDaFixture();
        $payload['dados'] = json_encode($procuracoes);
        unset($payload['_provenance']);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function payloadDaFixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/../Fixtures/serpro/procuracao-familias.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
