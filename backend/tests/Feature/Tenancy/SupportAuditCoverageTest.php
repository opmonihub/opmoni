<?php

namespace Tests\Feature\Tenancy;

use App\Enums\SerproSyncRunState;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientSavedFilter;
use App\Models\SerproConnection;
use App\Models\SerproSyncRun;
use App\Models\SupportAccessLog;
use App\Models\User;
use App\Services\SerproAccountEnablement;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O suporte tem o poder de um admin sem exceção, e toda escrita dele na
 * conta alheia deixa linha em `support_access_logs`. Cada teste cobre um
 * controller que escrevia sem registrar.
 */
class SupportAuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Account $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Cache::flush();
        Http::preventStrayRequests();
        Storage::fake('certificates');
        Queue::fake();
        resolve(CurrentTenant::class)->accountId = null;

        $this->superAdmin = $this->superAdminWithOwnAccount();
        $this->target = Account::factory()->create();
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_suporte_registra_envio_e_remocao_do_certificado_do_cliente(): void
    {
        $client = Client::factory()->individual()->create(['account_id' => $this->target->getKey()]);
        $this->entrarEmSuporte();

        $this->post(
            "/api/clients/{$client->getKey()}/certificate",
            ['certificate' => $this->pfxUpload('secret'), 'password' => 'secret'],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->deleteJson("/api/clients/{$client->getKey()}/certificate")->assertNoContent();

        $this->assertLog('clients', 'certificate', $client->getKey());
        $this->assertLog('clients', 'certificate-remove', $client->getKey());
        $this->assertLogsNaoGuardamASenha();
    }

    public function test_suporte_registra_associacao_de_modulos_de_monitoramento(): void
    {
        $client = Client::factory()->company()->create(['account_id' => $this->target->getKey()]);
        $this->entrarEmSuporte();

        $this->postJson("/api/clients/{$client->getKey()}/monitoring-modules", [
            'obligations' => ['declaracoes/pgdas', 'dctfweb'],
        ])->assertOk();

        $this->assertLog('clients', 'monitoring-modules', $client->getKey());
    }

    public function test_suporte_registra_atualizacao_cadastral_pelo_cnpj(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response($this->cnpjPayload())]);
        $client = Client::factory()->company()->create([
            'account_id' => $this->target->getKey(),
            'tax_id' => '27865757000102',
        ]);
        $this->entrarEmSuporte();

        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh")->assertOk();

        $this->assertLog('clients', 'cnpj_refresh', $client->getKey());
    }

    public function test_suporte_registra_criacao_e_remocao_de_filtro_salvo(): void
    {
        $this->entrarEmSuporte();

        $id = $this->postJson('/api/clients/saved-filters', ['name' => 'Ativos', 'q' => 'ltda', 'filters' => []])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/clients/saved-filters/{$id}")->assertNoContent();

        $this->assertLog('client_saved_filters', 'create', $id);
        $this->assertLog('client_saved_filters', 'delete', $id);
        $this->assertSame(0, ClientSavedFilter::withoutGlobalScopes()->count());
    }

    public function test_suporte_registra_criacao_de_selecao(): void
    {
        Client::factory()->company()->create(['account_id' => $this->target->getKey()]);
        $this->entrarEmSuporte();

        $this->postJson('/api/clients/selections', [])->assertOk();

        $this->assertLog('client_selections', 'create');
    }

    public function test_suporte_registra_mudanca_da_habilitacao_serpro(): void
    {
        $this->entrarEmSuporte();

        $this->putJson('/api/serpro/enablement', ['enabled' => false])->assertOk();

        $this->assertLog('serpro_enablement', 'update');
    }

    public function test_suporte_registra_disparo_e_resync_da_sincronizacao(): void
    {
        $this->conexao();
        resolve(SerproAccountEnablement::class)->set($this->target->getKey(), true);
        $anterior = SerproSyncRun::factory()->create([
            'account_id' => $this->target->getKey(),
            'state' => SerproSyncRunState::Completed,
            'finished_at' => now(),
        ]);
        $this->entrarEmSuporte();

        $novaId = $this->postJson('/api/serpro/sync-runs')->assertStatus(202)->json('data.id');
        SerproSyncRun::withoutGlobalScopes()->whereKey($novaId)->update([
            'state' => SerproSyncRunState::Completed,
            'finished_at' => now(),
        ]);

        $resyncId = $this->postJson("/api/serpro/sync-runs/{$anterior->getKey()}/resync")
            ->assertStatus(202)
            ->json('data.id');

        $this->assertLog('serpro_sync_runs', 'create', $novaId);
        $this->assertLog('serpro_sync_runs', 'resync', $resyncId);
    }

    public function test_suporte_registra_associacao_de_clientes_a_obrigacao(): void
    {
        $client = Client::factory()->company()->create(['account_id' => $this->target->getKey()]);
        $this->entrarEmSuporte();

        $this->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients', [
            'client_ids' => [$client->getKey()],
        ])->assertOk();

        $this->assertLog('serpro_monitorings', 'associate');
    }

    public function test_membro_nao_gera_log_nas_mesmas_escritas(): void
    {
        $client = Client::factory()->company()->create(['account_id' => $this->target->getKey()]);
        $this->actingAs($this->memberOf($this->target, 'admin'), 'sanctum');

        $this->postJson('/api/clients/saved-filters', ['name' => 'Ativos', 'q' => 'ltda', 'filters' => []])->assertCreated();
        $this->postJson('/api/clients/selections', [])->assertOk();

        // A escrita do flag de habilitação é só do super_admin: para o Membro
        // ela é `403`, e continua sem gerar log — a recusa vem antes de
        // qualquer `SupportAudit::logWrite`.
        $this->putJson('/api/serpro/enablement', ['enabled' => false])->assertForbidden();

        $this->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients', [
            'client_ids' => [$client->getKey()],
        ])->assertOk();

        $this->assertSame(0, SupportAccessLog::count());
    }

    private function entrarEmSuporte(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$this->target->getKey()}/enter")
            ->assertOk();
    }

    private function assertLog(string $resource, string $action, ?int $resourceId = null): void
    {
        $query = SupportAccessLog::query()
            ->where('super_admin_user_id', $this->superAdmin->getKey())
            ->where('account_id', $this->target->getKey())
            ->where('action', $action)
            ->where('metadata->resource', $resource);

        if ($resourceId !== null) {
            $query->where('metadata->resource_id', $resourceId);
        }

        $this->assertTrue($query->exists(), "Faltou log {$resource}.{$action} em modo suporte.");
    }

    private function assertLogsNaoGuardamASenha(): void
    {
        foreach (SupportAccessLog::all() as $log) {
            $this->assertStringNotContainsString('secret', (string) json_encode($log->metadata));
        }
    }

    private function superAdminWithOwnAccount(): User
    {
        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $home = Account::factory()->create();

        AccountUser::create([
            'account_id' => $home->getKey(),
            'user_id' => $superAdmin->getKey(),
            'role' => 'admin',
        ]);

        $superAdmin->forceFill(['current_account_id' => $home->getKey()])->save();

        return $superAdmin->refresh();
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    private function conexao(): void
    {
        if (SerproConnection::current() !== null) {
            return;
        }

        SerproConnection::factory()->create([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
        ]);
    }

    private function pfxUpload(string $password): UploadedFile
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA], $config));
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['CN' => 'Teste'], $key, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $this->assertNotFalse($cert);
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($cert, $pfx, $key, $password));

        return UploadedFile::fake()->createWithContent('cliente.pfx', $pfx);
    }

    /**
     * @return array<string, mixed>
     */
    private function cnpjPayload(): array
    {
        return [
            'razao_social' => 'GLOBO COMUNICACAO E PARTICIPACOES S/A',
            'porte' => ['descricao' => 'Demais'],
            'natureza_juridica' => ['descricao' => 'Sociedade Anônima Fechada'],
            'socios' => [],
            'simples' => ['mei' => 'Não', 'simples' => 'Não'],
            'estabelecimento' => [
                'cnpj' => '27865757000102',
                'nome_fantasia' => 'GLOBOPLAY',
                'situacao_cadastral' => 'Ativa',
                'data_situacao_cadastral' => '2005-11-03',
                'data_inicio_atividade' => '1986-01-31',
                'tipo_logradouro' => 'RUA',
                'logradouro' => 'LOPES QUINTAS',
                'numero' => '303',
                'complemento' => null,
                'bairro' => 'JARDIM BOTANICO',
                'cep' => '22460901',
                'ddd1' => '21',
                'telefone1' => '21554551',
                'email' => 'fiscal@example.com',
                'atualizado_em' => '2026-09-12T03:00:00.000Z',
                'atividade_principal' => ['id' => '6021700', 'descricao' => 'Atividades de televisão aberta'],
                'estado' => ['sigla' => 'RJ'],
                'cidade' => ['nome' => 'Rio de Janeiro'],
            ],
        ];
    }
}
