<?php

namespace Tests\Feature\Serpro;

use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

/**
 * A etapa de módulos do cadastro: GET devolve o catálogo servido com a
 * sugestão do regime e a associação atual, e POST grava os vínculos — sem
 * chamar o provedor, sem criar execução e sem gastar cota.
 */
class ClientMonitoringModulesTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_get_devolve_o_catalogo_servido_com_sugestao_do_regime(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::SimpleNational,
        ]);

        $response = $this->actingAs($this->memberOf($account, 'user'), 'web')
            ->getJson("/api/v1/clients/{$client->getKey()}/monitoring-modules")
            ->assertOk()
            ->assertJsonPath('data.regime', 'simple_national');

        $obligations = collect($response->json('data.obligations'))->keyBy('slug');

        // Só `direct` e `derived` entram: `unavailable` e `extinct` não têm
        // fonte, e uma linha ali fingiria que o provedor um dia responderá.
        foreach (['parcelamentos/pgfn', 'declaracoes/fgts', 'declaracoes/dirf'] as $slug) {
            $this->assertFalse($obligations->has($slug), "a obrigação não servida {$slug} entrou na lista");
        }

        // O mapa do Simples: PGDAS é sugerida, DCTFWeb não é — e cada item
        // carrega o que a etapa precisa para desenhar e marcar.
        $pgdas = $obligations['declaracoes/pgdas'];
        $this->assertSame(
            ['slug', 'label', 'category', 'suggested', 'associated'],
            array_keys($pgdas),
        );
        $this->assertSame('PGDAS', $pgdas['label']);
        $this->assertSame('direct', $pgdas['category']);
        $this->assertTrue($pgdas['suggested']);
        $this->assertFalse($pgdas['associated']);

        $this->assertFalse($obligations['dctfweb']['suggested']);
        $this->assertTrue($obligations['mei']['category'] === 'direct');

        Http::assertNothingSent();
    }

    public function test_get_marca_a_associada_e_a_sugestao_muda_com_o_regime(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $mei = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::Mei,
        ]);
        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $mei->getKey(),
            'obligation' => 'caixas-postais/e-cac',
        ]);

        $obligations = collect(
            $this->actingAs($this->memberOf($account, 'operador'), 'web')
                ->getJson("/api/v1/clients/{$mei->getKey()}/monitoring-modules")
                ->assertOk()
                ->assertJsonPath('data.regime', 'mei')
                ->json('data.obligations')
        )->keyBy('slug');

        // MEI sugere PGMEI e não PGDAS: nada do Simples Nacional entra para
        // quem não é do regime.
        $this->assertTrue($obligations['mei']['suggested']);
        $this->assertFalse($obligations['declaracoes/pgdas']['suggested']);
        $this->assertFalse($obligations['simples-nacional']['suggested']);

        // A já associada vem marcada: a etapa lê o vínculo que existe e não
        // oferece remover.
        $this->assertTrue($obligations['caixas-postais/e-cac']['associated']);
        $this->assertFalse($obligations['mei']['associated']);
    }

    public function test_get_sem_regime_devolve_o_catalogo_sem_sugestao(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => null,
        ]);

        $obligations = collect(
            $this->actingAs($this->memberOf($account, 'user'), 'web')
                ->getJson("/api/v1/clients/{$client->getKey()}/monitoring-modules")
                ->assertOk()
                ->assertJsonPath('data.regime', null)
                ->json('data.obligations')
        );

        // Sem regime não há sugestão: o catálogo continua servido, mas nada
        // vem marcado para o operador.
        $this->assertNotEmpty($obligations);
        foreach ($obligations as $obrigacao) {
            $this->assertFalse($obrigacao['suggested']);
        }

        Http::assertNothingSent();
    }

    public function test_post_associa_os_slugs_e_conta_o_ja_associado(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'caixas-postais/e-cac',
        ]);

        $this->actingAs($this->memberOf($account, 'operador'), 'web')
            ->postJson("/api/v1/clients/{$client->getKey()}/monitoring-modules", [
                'obligations' => ['declaracoes/pgdas', 'caixas-postais/e-cac'],
            ])
            ->assertOk()
            ->assertJsonPath('data.associated', 1)
            ->assertJsonPath('data.already', 1);

        // A linha nasce pedida, não respondida: `source_at` vazio é o que a
        // mantém fora dos números até a próxima execução.
        $linha = SerproMonitoring::query()
            ->where('client_id', $client->getKey())
            ->where('obligation', 'declaracoes/pgdas')
            ->sole();
        $this->assertSame('sem_dados', $linha->state);
        $this->assertNull($linha->source_at);
        $this->assertSame($account->getKey(), $linha->account_id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('serpro_sync_runs', 0);
    }

    public function test_post_recusa_slug_desconhecido_nao_servido_lista_vazia_e_pf(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $pj = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $pf = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $member = $this->memberOf($account, 'admin');

        $this->actingAs($member, 'web')
            ->postJson("/api/v1/clients/{$pj->getKey()}/monitoring-modules", ['obligations' => ['nao-existe']])
            ->assertUnprocessable();

        foreach (['parcelamentos/pgfn', 'declaracoes/dirf'] as $slug) {
            $this->actingAs($member, 'web')
                ->postJson("/api/v1/clients/{$pj->getKey()}/monitoring-modules", ['obligations' => [$slug]])
                ->assertUnprocessable();
        }

        $this->actingAs($member, 'web')
            ->postJson("/api/v1/clients/{$pj->getKey()}/monitoring-modules", ['obligations' => []])
            ->assertUnprocessable();

        // Pessoa física não tem módulo: a integração só age por PJ, e uma
        // linha ali seria uma associação que nenhuma execução consulta.
        $this->actingAs($member, 'web')
            ->postJson("/api/v1/clients/{$pf->getKey()}/monitoring-modules", ['obligations' => ['declaracoes/pgdas']])
            ->assertUnprocessable();

        $this->assertSame(0, SerproMonitoring::count());
        Http::assertNothingSent();
    }

    public function test_user_le_os_modulos_mas_nao_confirma(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $reader = $this->memberOf($account, 'user');

        $this->actingAs($reader, 'web')
            ->getJson("/api/v1/clients/{$client->getKey()}/monitoring-modules")
            ->assertOk();

        $this->actingAs($reader, 'web')
            ->postJson("/api/v1/clients/{$client->getKey()}/monitoring-modules", ['obligations' => ['declaracoes/pgdas']])
            ->assertForbidden();

        $this->assertSame(0, SerproMonitoring::count());
    }

    public function test_cliente_de_outra_account_responde_404(): void
    {
        $alheio = Client::factory()->company()->create();
        $member = $this->memberOf(Account::factory()->create(), 'admin');

        $this->actingAs($member, 'web')
            ->getJson("/api/v1/clients/{$alheio->getKey()}/monitoring-modules")
            ->assertNotFound();

        $this->actingAs($member, 'web')
            ->postJson("/api/v1/clients/{$alheio->getKey()}/monitoring-modules", ['obligations' => ['declaracoes/pgdas']])
            ->assertNotFound();
    }

    public function test_acesso_de_suporte_associa_e_audita_os_slugs_requires_real_membership(): void
    {
        $targetAccount = Account::factory()->create();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $targetAccount->id);
        $this->actingAs($superAdmin)->postJson('/api/v1/clients/1/monitoring-modules', [])
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
