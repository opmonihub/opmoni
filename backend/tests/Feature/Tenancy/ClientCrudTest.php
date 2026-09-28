<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_operador_creates_and_reads_normalized_manual_individual(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');

        $id = $this->postJson('/api/clients', $this->payload([
            'tax_id' => '529.982.247-25', 'phone' => '(21) 2155-4551',
            'postal_code' => '22460-901', 'street' => 'Rua Local', 'state' => 'RJ',
            'account_id' => Account::factory()->create()->getKey(),
            'socios' => [['name' => 'Ignored']], 'trade_name' => 'Ignored',
        ]))->assertCreated()->assertJsonPath('data.tax_id', '52998224725')
            ->assertJsonPath('data.phone', '2121554551')
            ->assertJsonPath('data.address.postal_code', '22460901')
            ->assertJsonPath('data.trade_name', null)
            ->assertJsonMissingPath('data.account_id')->assertJsonMissingPath('data.socios')->json('data.id');

        $this->getJson("/api/clients/{$id}")->assertOk()->assertJsonPath('data.name', 'Cliente Teste');
        $this->assertDatabaseHas('clients', ['id' => $id, 'account_id' => $account->getKey()]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_individual_payload_returns_422(array $overrides, string $field): void
    {
        $this->actingAs($this->memberOf(Account::factory()->create()), 'sanctum');
        $this->postJson('/api/clients', $this->payload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('clients', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            [['tax_id' => '52998224724'], 'tax_id'],
            [['tax_id' => ['52998224725']], 'tax_id'],
            [['name' => null], 'name'],
            [['person_type' => 'unknown'], 'person_type'],
            [['status' => 'unknown'], 'status'],
            [['tax_regime' => 'actual_profit'], 'tax_regime'],
            [['email' => 'invalid'], 'email'],
            [['postal_code' => '123'], 'postal_code'],
        ];
    }

    public function test_name_only_creation_is_no_longer_accepted(): void
    {
        $this->actingAs($this->memberOf(Account::factory()->create()), 'sanctum');
        $this->postJson('/api/clients', ['name' => 'Legacy'])->assertUnprocessable()
            ->assertJsonValidationErrors(['person_type', 'tax_id', 'status', 'tax_regime']);
    }

    public function test_operador_creates_company_from_server_lookup(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response($this->companyFixture())]);
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson('/api/clients', [
            'person_type' => 'company',
            'tax_id' => '27.865.757/0001-02',
            'status' => 'active',
            'tax_regime' => 'actual_profit',
            'email' => 'contato@example.com',
            'phone' => '2121554551',
        ])->assertCreated()
            ->assertJsonPath('data.tax_id', '27865757000102')
            ->assertJsonPath('data.name', 'GLOBO COMUNICACAO E PARTICIPACOES S/A')
            ->assertJsonPath('data.email', 'contato@example.com')
            ->assertJsonMissingPath('data.socios');

        $this->assertDatabaseHas('clients', ['account_id' => $account->getKey(), 'tax_id' => '27865757000102']);
    }

    public function test_company_create_aceita_cnpj_alfanumerico_e_recusa_verificador_errado(): void
    {
        // O documento gravado vem do payload da Receita, então o fixture devolve o
        // mesmo CNPJ alfanumérico enviado; a consulta pública ainda é só numérica.
        $fixture = $this->companyFixture();
        $fixture['estabelecimento']['cnpj'] = '12ABC345000188';
        Http::fake(['publica.cnpj.ws/*' => Http::response($fixture)]);
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson('/api/clients', [
            'person_type' => 'company',
            'tax_id' => '12.ABC.345/0001-88',
            'status' => 'active',
            'tax_regime' => 'actual_profit',
        ])->assertCreated()->assertJsonPath('data.tax_id', '12ABC345000188');

        $this->assertDatabaseHas('clients', ['account_id' => $account->getKey(), 'tax_id' => '12ABC345000188']);

        $this->postJson('/api/clients', [
            'person_type' => 'company',
            'tax_id' => '12ABC345000189',
            'status' => 'active',
            'tax_regime' => 'actual_profit',
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_id');

        $this->assertSame(1, $account->clients()->count());
    }

    public function test_company_create_ignores_browser_preview_fields_and_forces_mei_regime(): void
    {
        $fixture = $this->companyFixture();
        $fixture['simples'] = ['mei' => 'Sim', 'simples' => 'Sim'];
        Http::fake(['publica.cnpj.ws/*' => Http::response($fixture)]);
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->postJson('/api/clients', [
            'person_type' => 'company',
            'tax_id' => '27865757000102',
            'name' => 'Nome Falso do Navegador',
            'status' => 'active',
            'tax_regime' => 'actual_profit',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'GLOBO COMUNICACAO E PARTICIPACOES S/A')
            ->assertJsonPath('data.tax_regime', 'mei');
    }

    public function test_company_create_rejects_incompatible_manual_regime(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response($this->companyFixture())]);
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->postJson('/api/clients', [
            'person_type' => 'company',
            'tax_id' => '27865757000102',
            'status' => 'active',
            'tax_regime' => 'not_applicable',
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_regime');

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_company_create_maps_lookup_failure_to_provider_status_and_preserves_data(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response([], 500)]);
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->postJson('/api/clients', [
            'person_type' => 'company',
            'tax_id' => '27865757000102',
            'status' => 'active',
            'tax_regime' => 'actual_profit',
        ])->assertStatus(503);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_client_create_beyond_plan_limit_returns_422(): void
    {
        $account = Account::factory()->create();
        Client::factory()->count(50)->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson('/api/clients', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('limit');

        $this->assertSame(50, $account->clients()->count());
    }

    public function test_duplicate_document_returns_validation_error_but_other_account_is_allowed(): void
    {
        Client::factory()->individual()->create(['tax_id' => '52998224725']);
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');
        $this->postJson('/api/clients', $this->payload())->assertCreated();
        $this->postJson('/api/clients', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->assertSame(1, $account->clients()->count());
    }

    public function test_user_can_read_but_cannot_write_clients(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'user'), 'sanctum');
        $this->getJson('/api/clients')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/clients/{$client->id}")->assertOk();
        $this->postJson('/api/clients', [])->assertForbidden();
        $this->patchJson("/api/clients/{$client->id}", ['status' => 'inactive'])->assertForbidden();
        $this->deleteJson("/api/clients/{$client->id}")->assertForbidden();
    }

    public function test_cross_account_binding_is_404_for_all_client_actions(): void
    {
        $client = Client::factory()->company()->create();
        $this->actingAs($this->memberOf(Account::factory()->create()), 'sanctum');
        $this->getJson("/api/clients/{$client->id}")->assertNotFound();
        $this->patchJson("/api/clients/{$client->id}", [])->assertNotFound();
        $this->deleteJson("/api/clients/{$client->id}")->assertNotFound();
        $this->postJson("/api/clients/{$client->id}/cnpj-refresh-preview")->assertNotFound();
        $this->postJson("/api/clients/{$client->id}/cnpj-refresh")->assertNotFound();
    }

    public function test_individual_update_preserves_identity_and_normalizes_contacts(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');
        $this->patchJson("/api/clients/{$client->id}", [
            'name' => 'Novo Nome', 'phone' => '(21) 2155-4551', 'postal_code' => '22460-901', 'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.name', 'Novo Nome')->assertJsonPath('data.phone', '2121554551')
            ->assertJsonPath('data.address.postal_code', '22460901')->assertJsonPath('data.status', 'inactive');
        foreach (['person_type' => 'company', 'tax_id' => '52998224725', 'tax_regime' => 'mei'] as $field => $value) {
            $this->patchJson("/api/clients/{$client->id}", [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_mei_company_patch_to_actual_profit_is_forced_back_to_mei(): void
    {
        $fixture = $this->companyFixture();
        $fixture['simples'] = ['mei' => 'Sim', 'simples' => 'Sim'];
        Http::fake(['publica.cnpj.ws/*' => Http::response($fixture)]);
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_id' => '27865757000102', 'tax_regime' => 'mei']);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->patchJson("/api/clients/{$client->id}", ['tax_regime' => 'actual_profit'])->assertOk()
            ->assertJsonPath('data.tax_regime', 'mei');
    }

    public function test_non_simples_company_rejects_mei_and_simple_national_patch(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response($this->companyFixture())]);
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_id' => '27865757000102', 'tax_regime' => 'actual_profit']);
        $this->actingAs($this->memberOf($account), 'sanctum');

        foreach (['mei', 'simple_national'] as $regime) {
            $this->patchJson("/api/clients/{$client->id}", ['tax_regime' => $regime])->assertUnprocessable()
                ->assertJsonValidationErrors('tax_regime');
        }

        $this->assertSame('actual_profit', $client->refresh()->tax_regime->value);
    }

    public function test_delete_is_soft_and_recreating_restores_same_identity_with_new_data(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');
        $id = $this->postJson('/api/clients', $this->payload())->assertCreated()->json('data.id');
        $this->deleteJson("/api/clients/{$id}")->assertNoContent();
        $this->assertSoftDeleted('clients', ['id' => $id]);
        $this->getJson("/api/clients/{$id}")->assertNotFound();
        $this->getJson('/api/clients')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/clients', $this->payload(['name' => 'Restaurado']))->assertCreated()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.name', 'Restaurado');
        $this->assertDatabaseCount('clients', 1);
        $this->assertNotSoftDeleted('clients', ['id' => $id]);
    }

    public function test_index_paginates_searches_filters_and_sorts_with_tenant_isolation(): void
    {
        $account = Account::factory()->create();
        $a = Client::factory()->individual()->create(['account_id' => $account->id, 'name' => 'Alpha', 'tax_id' => '52998224725']);
        Client::factory()->company()->create(['account_id' => $account->id, 'name' => 'Zulu', 'trade_name' => 'Loja Azul', 'status' => 'inactive']);
        Client::factory()->count(24)->create(['account_id' => $account->id, 'name' => 'Middle']);
        Client::factory()->individual()->create(['name' => 'Alpha Alheio']);
        $this->actingAs($this->memberOf($account), 'sanctum');
        $this->getJson('/api/clients?per_page=25&sort=name&direction=desc&page=2')->assertOk()
            ->assertJsonPath('meta.total', 26)->assertJsonPath('meta.current_page', 2)->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/clients?q=Alpha&status=active&tax_regime=not_applicable')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/clients?q=529.982.247-25')->assertOk()->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/clients?q=Azul')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Zulu');
        $this->getJson('/api/clients?status=inactive&tax_regime=not_applicable')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_index_returns_the_full_portfolio_when_all_is_requested(): void
    {
        $account = Account::factory()->create();
        Client::factory()->count(30)->create(['account_id' => $account->id, 'name' => 'Carteira']);
        Client::factory()->create(['name' => 'Outra conta']);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $response = $this->getJson('/api/clients?all=1&sort=name')->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.total', 30);
        $this->assertArrayNotHasKey('current_page', $response->json('meta'));
    }

    #[DataProvider('invalidIndexQueries')]
    public function test_index_rejects_invalid_query_parameters(string $query, string $field): void
    {
        $this->actingAs($this->memberOf(Account::factory()->create()), 'sanctum');
        $this->getJson('/api/clients?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidIndexQueries(): array
    {
        return [['sort=account_id', 'sort'], ['direction=sideways', 'direction'], ['per_page=101', 'per_page'],
            ['per_page=15', 'per_page'], ['per_page=1', 'per_page'], ['per_page=0', 'per_page'],
            ['page=0', 'page'], ['status=bad', 'status'], ['tax_regime=bad', 'tax_regime']];
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->id])->save();

        return $user->refresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['person_type' => 'individual', 'tax_id' => '52998224725', 'name' => 'Cliente Teste',
            'status' => 'active', 'tax_regime' => 'not_applicable'], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyFixture(): array
    {
        return [
            'razao_social' => 'GLOBO COMUNICACAO E PARTICIPACOES S/A',
            'porte' => ['descricao' => 'Demais'],
            'natureza_juridica' => ['descricao' => 'Sociedade Anônima Fechada'],
            'socios' => [['cpf_cnpj_socio' => '07561099738', 'nome' => 'Dado excluído']],
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

    public function test_same_tax_id_is_unique_inside_account(): void
    {
        $first = Account::factory()->create();

        Client::factory()->company()->create([
            'account_id' => $first->getKey(),
            'tax_id' => '27865757000102',
        ]);

        $this->expectException(QueryException::class);
        Client::factory()->company()->create([
            'account_id' => $first->getKey(),
            'tax_id' => '27865757000102',
        ]);
    }

    public function test_same_tax_id_is_allowed_in_another_account(): void
    {
        $first = Account::factory()->create();
        $second = Account::factory()->create();

        Client::factory()->company()->create([
            'account_id' => $first->getKey(),
            'tax_id' => '27865757000102',
        ]);

        $client = Client::factory()->company()->create([
            'account_id' => $second->getKey(),
            'tax_id' => '27865757000102',
        ]);

        $this->assertSame($second->getKey(), $client->account_id);
    }

    public function test_soft_deleted_client_is_hidden_and_does_not_count_toward_plan_limit(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $client->delete();

        $this->assertSame(0, $account->clients()->count());
        $this->assertSame(1, Client::withoutGlobalScopes()->withTrashed()->count());
    }

    public function test_search_does_not_match_unrelated_clients_for_alphabetic_term(): void
    {
        Client::factory()->company()->create(['name' => 'Empresa Alpha']);

        $this->assertSame(0, Client::search('unmatched')->count());
    }
}
