<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Jobs\SyncSerproClientJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O escopo da execução: a run com `obligations` preenchido cobre só a lista,
 * e o trabalhador não cobra do gateway serviço que ficou de fora. `null` é
 * "todas as sincronizáveis" — a execução de botão continua inteira.
 */
class SerproRunObligationScopeTest extends TestCase
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

    public function test_a_run_com_escopo_cobra_so_a_obrigacao_da_lista(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();
        $run->forceFill(['obligations' => ['declaracoes/pgdas']])->save();

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        // A primeira entrega é o oráculo — e a resposta dele rejeita a
        // família que não veio no payload (00146 entre elas); o
        // `serpro:refresh-powers` é quem reestabelece, e é o que a re-semeada
        // abaixo encena antes da entrega seguinte.
        $job->handle();
        $this->restabelecerOutorga($account->getKey(), $client->getKey(), '00146');

        for ($i = 0; $i < 8; $i++) {
            $job->handle();
        }

        // Oráculo + a obrigação do escopo. O e-CAC (00006) está concedido e
        // FORA do escopo — e é justamente ele que nunca é cobrado.
        $this->assertSame(
            ['OBTERPROCURACAO41', 'CONSDECLARACAO13'],
            SerproCall::query()->withoutGlobalScope('account')->orderBy('id')->pluck('id_servico')->all(),
        );
    }

    public function test_a_run_sem_escopo_mantem_o_leque_inteiro(): void
    {
        [$account, $client, $run] = $this->cenarioChamavel();

        $job = new SyncSerproClientJob($run->getKey(), $account->getKey(), $client->getKey());
        $job->handle();
        $this->restabelecerOutorga($account->getKey(), $client->getKey(), '00146');

        for ($i = 0; $i < 8; $i++) {
            $job->handle();
        }

        // `null` é o comportamento de sempre: oráculo + caixa postal + pgdas,
        // na ordem do catálogo.
        $this->assertSame(
            ['OBTERPROCURACAO41', 'MSGCONTRIBUINTE61', 'CONSDECLARACAO13'],
            SerproCall::query()->withoutGlobalScope('account')->orderBy('id')->pluck('id_servico')->all(),
        );
    }

    /**
     * O `persist` do oráculo rejeita o que a resposta não repetiu — re-semar
     * a outorga é o que simula o estado que `serpro:refresh-powers`
     * manteria entre as entregas.
     */
    private function restabelecerOutorga(int $accountId, int $clientId, string $family): void
    {
        SerproClientAuthorization::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('family', $family)
            ->update([
                'state' => SerproPowerOfAttorneyState::Established->value,
                'verified_at' => now(),
            ]);
    }

    /**
     * Conta com termo vigente, certificado, PJ com documento e as outorgas
     * das famílias que o caixa postal (00006) e o pgdas (00146) exigem.
     *
     * @return array{0: Account, 1: Client, 2: SerproSyncRun}
     */
    private function cenarioChamavel(): array
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);

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

        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
            '*/integra-contador/v1/Consultar' => function ($request) {
                $servico = $request->data()['pedidoDados']['idServico'] ?? '';

                // O oráculo concede o que o provedor nomeia — a resposta que
                // re-estabelece a família do caixa postal e rejeita o resto.
                return Http::response([
                    'status' => 200,
                    'dados' => $servico === 'OBTERPROCURACAO41'
                        ? '[{"dtexpiracao":"20270101","nrsistemas":"1","sistemas":["Caixa Postal - Mensagens"]}]'
                        : '{}',
                    'mensagens' => [['codigo' => 'Sucesso', 'texto' => 'Requisição efetuada com sucesso']],
                    'responseId' => 'resp-'.$servico,
                ]);
            },
            '*' => Http::response(['status' => 200, 'dados' => '{}', 'mensagens' => [], 'responseId' => 'resp-2']),
        ]);

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '33683111000875',
        ]);

        // As outorgas que a elegibilidade lê — o oráculo não corre aqui de
        // novo porque a chamada fake de `Consultar` já responde a tudo.
        foreach (['00006', '00146'] as $family) {
            SerproClientAuthorization::factory()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
                'family' => $family,
                'code' => $family,
            ]);
        }

        $run = SerproSyncRun::factory()->create(['account_id' => $account->getKey()]);
        SerproSyncRunItem::factory()->create([
            'account_id' => $account->getKey(),
            'run_id' => $run->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return [$account, $client, $run];
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
