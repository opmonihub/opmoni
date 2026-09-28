<?php

namespace Tests\Feature;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientEcacPowerOfAttorney;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproClientAuthorization;
use App\Services\SerproEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A elegibilidade por família: a resposta read-only que decide se o cliente
 * pode ser chamado naquele serviço. Cada recusa tem um código próprio, e
 * "de uma família" nunca empresta para a outra.
 */
class SerproEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-27 12:00:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_pessoa_fisica_nunca_e_elegivel(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00002');

        $this->assertSame(
            ['eligible' => false, 'reason' => 'pessoa_fisica', 'expires_on' => null],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );

        // Nem a autorização nem o termo mudam a resposta — e nenhuma ida à
        // rede acontece para descobrir isso.
        Http::assertNothingSent();
    }

    public function test_sem_linha_de_autorizacao_o_motivo_e_sem_procuracao(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);

        $this->assertSame(
            ['eligible' => false, 'reason' => 'sem_procuracao', 'expires_on' => null],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );
    }

    public function test_estados_que_nao_sao_established_reproduzem_procuracao_invalida(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $eligibility = resolve(SerproEligibility::class);

        foreach ([SerproPowerOfAttorneyState::Pending, SerproPowerOfAttorneyState::Rejected] as $state) {
            $this->autorizacao($account, $client, '00002', $state, null);

            $this->assertSame(
                ['eligible' => false, 'reason' => 'procuracao_invalida', 'expires_on' => null],
                $eligibility->for($account->getKey(), $client->getKey(), '00002'),
            );
        }
    }

    public function test_outorga_vencida_e_procuracao_invalida(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00002', SerproPowerOfAttorneyState::Established, '2026-09-26');

        $this->assertSame(
            ['eligible' => false, 'reason' => 'procuracao_invalida', 'expires_on' => '2026-09-26'],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );
    }

    public function test_procuracao_do_membro_que_comeca_amanha_ainda_nao_vale(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00002', SerproPowerOfAttorneyState::Established, '2027-12-31');
        ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'starts_at' => '2026-09-28',
            'expires_at' => '2027-12-31',
        ]);

        $this->assertSame(
            ['eligible' => false, 'reason' => 'procuracao_invalida', 'expires_on' => '2027-12-31'],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );
    }

    public function test_procuracao_do_membro_vencida_ontem_nao_vale(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00002', SerproPowerOfAttorneyState::Established, '2027-12-31');
        ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'starts_at' => '2026-01-01',
            'expires_at' => '2026-09-26',
        ]);

        $this->assertSame(
            ['eligible' => false, 'reason' => 'procuracao_invalida', 'expires_on' => '2027-12-31'],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );
    }

    public function test_procuracao_vigente_elegibiliza_e_devolve_o_vencimento(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00002', SerproPowerOfAttorneyState::Established, '2026-10-27');

        $this->assertSame(
            ['eligible' => true, 'reason' => null, 'expires_on' => '2026-10-27'],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );

        Http::assertNothingSent();
    }

    public function test_outorga_que_expira_hoje_ainda_vale(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00002', SerproPowerOfAttorneyState::Established, '2026-09-27');

        // O dia inteiro vale: "vence hoje" não é "venceu".
        $this->assertSame(
            ['eligible' => true, 'reason' => null, 'expires_on' => '2026-09-27'],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );
    }

    public function test_familia_estabelecida_nao_empresta_para_outra(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00006', SerproPowerOfAttorneyState::Established, '2027-01-01');

        $this->assertSame(
            ['eligible' => false, 'reason' => 'sem_procuracao', 'expires_on' => null],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );
    }

    public function test_pgdasd_e_defis_dividem_a_familia_00146(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($account, $client, '00146', SerproPowerOfAttorneyState::Established, '2027-01-01');

        // O código `00146` é uma outorga só para as duas obrigações: a mesma
        // família elegibiliza as duas, porque nenhuma procuração separada
        // existe para ser pedida.
        $eligibility = resolve(SerproEligibility::class);
        $this->assertTrue($eligibility->for($account->getKey(), $client->getKey(), '00146')['eligible']);
    }

    public function test_sem_termo_o_cliente_nao_e_elegivel(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->autorizacao($account, $client, '00002', SerproPowerOfAttorneyState::Established, '2027-01-01');

        $this->assertSame(
            ['eligible' => false, 'reason' => 'sem_termo', 'expires_on' => null],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), '00002'),
        );
    }

    public function test_procuracoes_nao_exige_procuracao_mas_exige_termo(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->assertSame(
            ['eligible' => false, 'reason' => 'sem_termo', 'expires_on' => null],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), 'PROCURACOES'),
        );

        $this->termoVigente($account);

        // A própria consulta de procuração é o oráculo: exigir procuração
        // para perguntar se há procuração seria circular.
        $this->assertSame(
            ['eligible' => true, 'reason' => null, 'expires_on' => null],
            resolve(SerproEligibility::class)->for($account->getKey(), $client->getKey(), 'PROCURACOES'),
        );
    }

    public function test_cliente_de_outra_conta_nao_existe_para_esta(): void
    {
        $account = Account::factory()->create();
        $outraConta = Account::factory()->create();
        $alheio = Client::factory()->company()->create(['account_id' => $outraConta->getKey()]);
        $this->termoVigente($account);
        $this->autorizacao($outraConta, $alheio, '00002', SerproPowerOfAttorneyState::Established, '2027-01-01');

        $this->expectException(ModelNotFoundException::class);

        resolve(SerproEligibility::class)->for($account->getKey(), $alheio->getKey(), '00002');
    }

    private function termoVigente(Account $account): void
    {
        SerproAuthorizationTerm::forceCreate([
            'account_id' => $account->getKey(),
            'author_document' => '12345678000195',
            'document_encrypted' => Crypt::encryptString('<termo/>'),
            'document_expires_on' => '2027-01-01',
            'signed_at' => now(),
            'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('token-do-termo'),
            'token_expires_at' => CarbonImmutable::parse('2026-09-28'),
        ]);
    }

    private function autorizacao(
        Account $account,
        Client $client,
        string $family,
        SerproPowerOfAttorneyState $state = SerproPowerOfAttorneyState::Established,
        ?string $expiresOn = '2027-01-01',
    ): void {
        SerproClientAuthorization::query()
            ->where('client_id', $client->getKey())
            ->where('family', $family)
            ->delete();

        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => $family,
            'code' => $family,
            'state' => $state,
            'expires_on' => $expiresOn,
        ]);
    }
}
