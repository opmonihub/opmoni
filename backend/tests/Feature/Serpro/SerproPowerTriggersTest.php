<?php

namespace Tests\Feature\Serpro;

use App\Enums\SerproAuthorizationTermState;
use App\Jobs\RefreshSerproPowersJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproClientAuthorization;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\SerproTokenPair;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\BusinessTestCase;

/**
 * Os dois gatilhos que alimentam o oráculo fora da sincronização: o
 * salvamento do cliente — criação e troca de CPF/CNPJ — e a rotina diária
 * `serpro:refresh-powers`, que percorre só as Accounts habilitadas.
 */
class SerproPowerTriggersTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_criar_pj_em_conta_habilitada_despacha_o_job_apos_o_201(): void
    {
        Queue::fake();
        $account = $this->contaHabilitada();
        $member = $this->memberOf($account, 'operador');

        $response = $this->actingAs($member, 'web')->postJson('/api/v1/clients', [
            'person_type' => 'company',
            'tax_id' => '12ABC345000188',
            'name' => 'Empresa Alfa Ltda',
            'status' => 'active',
            'tax_regime' => 'actual_profit',
        ]);

        // O 201 chega sem esperar o provedor: a consulta é o job, e o job é
        // depois do commit — uma falha dele nunca desfaz o cadastro.
        $response->assertCreated();

        Queue::assertPushed(RefreshSerproPowersJob::class, function (RefreshSerproPowersJob $job) use ($account): bool {
            return $job->accountId === $account->getKey() && $job->clientId !== 0;
        });
    }

    public function test_criar_pf_ou_em_conta_nao_habilitada_nao_despacha(): void
    {
        Queue::fake();
        $habilitada = $this->contaHabilitada();
        $membroHabilitada = $this->memberOf($habilitada, 'operador');

        // PF em conta habilitada: a integração só age por PJ.
        $this->actingAs($membroHabilitada, 'web')->postJson('/api/v1/clients', [
            'person_type' => 'individual',
            'tax_id' => '52998224725',
            'name' => 'Cliente PF',
            'status' => 'active',
            'tax_regime' => 'not_applicable',
        ])->assertCreated();

        Queue::assertNotPushed(RefreshSerproPowersJob::class);

        // PJ em conta desligada: o flag da conta é a porta.
        $desligada = Account::factory()->create();
        $this->actingAs($this->memberOf($desligada, 'operador'), 'web')
            ->postJson('/api/v1/clients', [
                'person_type' => 'company',
                'tax_id' => '12ABC345000188',
                'name' => 'Empresa Alfa Ltda',
                'status' => 'active',
                'tax_regime' => 'actual_profit',
            ])->assertCreated();

        Queue::assertNotPushed(RefreshSerproPowersJob::class);
    }

    public function test_update_sem_troca_de_documento_nao_despacha(): void
    {
        Queue::fake();
        $account = $this->contaHabilitada();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'operador'), 'web')
            ->patchJson("/api/v1/clients/{$client->getKey()}", ['status' => 'inactive'])
            ->assertOk();

        // O update comum não pode pagar uma chamada cobrável por edição de
        // e-mail: o gatilho é a troca de documento, não o salvamento.
        Queue::assertNotPushed(RefreshSerproPowersJob::class);
    }

    public function test_troca_de_cnpj_despacha_com_force(): void
    {
        Queue::fake();
        $account = $this->contaHabilitada();
        // O documento reescrito por um save do model é o caso que o gatilho
        // cobre: a troca de `tax_id` despacha com `force`, porque a janela de
        // 20 horas media o documento antigo.
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '27865757000102',
        ]);

        $client->update(['tax_id' => '33683111000875']);

        Queue::assertPushed(RefreshSerproPowersJob::class, function (RefreshSerproPowersJob $job) use ($account, $client): bool {
            return $job->accountId === $account->getKey()
                && $job->clientId === $client->getKey()
                && $job->force === true;
        });
    }

    public function test_em_suporte_o_logwrite_diz_que_o_oraculo_foi_enfileirado_requires_real_membership(): void
    {
        $targetAccount = Account::factory()->create();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $targetAccount->id);
        $this->actingAs($superAdmin)->postJson('/api/v1/clients/1/monitoring-modules', [])
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_o_comando_despacha_um_job_por_pj_ativo_de_conta_habilitada(): void
    {
        Queue::fake();

        $habilitada = $this->contaHabilitada();
        $pjAtivo = Client::factory()->company()->create(['account_id' => $habilitada->getKey()]);
        Client::factory()->individual()->create(['account_id' => $habilitada->getKey()]);
        Client::factory()->company()->create([
            'account_id' => $habilitada->getKey(),
            'status' => 'inactive',
        ]);

        $desligada = Account::factory()->create();
        Client::factory()->company()->create(['account_id' => $desligada->getKey()]);

        // E o tenant residual não pode definir de quem o comando fala: ele
        // itera as Accounts explicitamente.
        resolve(CurrentTenant::class)->accountId = $desligada->getKey();

        $this->artisan('serpro:refresh-powers')->assertSuccessful();

        Queue::assertPushed(RefreshSerproPowersJob::class, 1);
        Queue::assertPushed(RefreshSerproPowersJob::class, fn (RefreshSerproPowersJob $job): bool => $job->accountId === $habilitada->getKey()
            && $job->clientId === $pjAtivo->getKey());
    }

    public function test_o_comando_nao_despacha_o_pj_que_ja_tem_autorizacao_gravada(): void
    {
        Queue::fake();

        $habilitada = $this->contaHabilitada();
        $consultado = Client::factory()->company()->create(['account_id' => $habilitada->getKey()]);
        $novo = Client::factory()->company()->create(['account_id' => $habilitada->getKey()]);

        // Qualquer linha vale — concedida, recusada ou vencida: o provedor
        // já respondeu por este cliente, e a consulta cobrada é para quem
        // nunca foi consultado.
        SerproClientAuthorization::factory()->create([
            'account_id' => $habilitada->getKey(),
            'client_id' => $consultado->getKey(),
            'family' => '00006',
        ]);

        $this->artisan('serpro:refresh-powers')->assertSuccessful();

        Queue::assertPushed(RefreshSerproPowersJob::class, 1);
        Queue::assertPushed(RefreshSerproPowersJob::class, fn (RefreshSerproPowersJob $job): bool => $job->clientId === $novo->getKey());
    }

    /**
     * Conta com a integração ligada, o e-CNPJ e o termo que o job pede para
     * rodar — aqui o job é fakeado, e a preparação existe para o teste do
     * comando não despachar para conta sem condições.
     */
    private function contaHabilitada(): Account
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        if (SerproConnection::current() === null) {
            SerproConnection::factory()->create([
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
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

        Cache::put($this->serproTokenCacheKey(), new SerproTokenPair('access-1', 'jwt-1', 2008), 2008);

        return $account;
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
