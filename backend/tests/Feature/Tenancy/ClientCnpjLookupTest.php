<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use App\Services\CnpjLookupException;
use App\Services\CnpjWsLookup;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientCnpjLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_it_normalizes_the_cnpj_and_returns_only_the_normalized_company_fields(): void
    {
        Http::fake([
            'publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload()),
        ]);

        $result = resolve(CnpjWsLookup::class)->lookup('27.865.757/0001-02');

        $this->assertSame('27865757000102', $result['tax_id']);
        $this->assertSame('GLOBO COMUNICACAO E PARTICIPACOES S/A', $result['name']);
        $this->assertArrayNotHasKey('socios', $result);
        $this->assertSame([
            'tax_id',
            'name',
            'trade_name',
            'registration_status',
            'registration_status_date',
            'opened_at',
            'company_size',
            'legal_nature',
            'primary_activity_code',
            'primary_activity_description',
            'street_type',
            'street',
            'address_number',
            'address_complement',
            'district',
            'postal_code',
            'city',
            'state',
            'email',
            'phone',
            'mei',
            'simple_national',
            'source_updated_at',
            'looked_up_at',
        ], array_keys($result));
        $this->assertSame('2121554551', $result['phone']);
        $this->assertFalse($result['mei']);
        $this->assertFalse($result['simple_national']);
        $this->assertIsString($result['looked_up_at']);
        Http::assertSentCount(1);
    }

    public function test_it_caches_successful_lookups_by_normalized_cnpj(): void
    {
        Http::fake([
            'publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload()),
        ]);

        $service = resolve(CnpjWsLookup::class);
        $first = $service->lookup('27.865.757/0001-02');
        $second = $service->lookup('27865757000102');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_it_rejects_an_invalid_cnpj_without_calling_the_provider(): void
    {
        Http::fake();

        try {
            resolve(CnpjWsLookup::class)->lookup('27.865.757/0001-03');
            $this->fail('An invalid CNPJ should throw a lookup exception.');
        } catch (CnpjLookupException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('CNPJ inválido.', $exception->getMessage());
        }

        Http::assertSentCount(0);
    }

    public function test_it_maps_a_provider_not_found_to_a_404_lookup_exception(): void
    {
        Http::fake([
            'publica.cnpj.ws/cnpj/27865757000102' => Http::response([], 404),
        ]);

        try {
            resolve(CnpjWsLookup::class)->lookup('27865757000102');
            $this->fail('A missing CNPJ should throw a lookup exception.');
        } catch (CnpjLookupException $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('CNPJ não encontrado.', $exception->getMessage());
        }
    }

    public function test_it_maps_a_provider_rate_limit_to_a_429_lookup_exception(): void
    {
        Http::fake([
            'publica.cnpj.ws/cnpj/27865757000102' => Http::response([], 429),
        ]);

        try {
            resolve(CnpjWsLookup::class)->lookup('27865757000102');
            $this->fail('A provider rate limit should throw a lookup exception.');
        } catch (CnpjLookupException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertSame('O provedor limitou temporariamente as consultas.', $exception->getMessage());
        }
    }

    public function test_it_maps_connection_failures_to_a_503_lookup_exception_without_retrying(): void
    {
        Http::fake(Http::failedConnection('timeout'));

        try {
            resolve(CnpjWsLookup::class)->lookup('27865757000102');
            $this->fail('A provider connection failure should throw a lookup exception.');
        } catch (CnpjLookupException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('O serviço de consulta de CNPJ está indisponível.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_it_maps_provider_server_errors_to_a_503_lookup_exception(): void
    {
        Http::fake([
            'publica.cnpj.ws/cnpj/27865757000102' => Http::response([], 500),
        ]);

        try {
            resolve(CnpjWsLookup::class)->lookup('27865757000102');
            $this->fail('A provider server error should throw a lookup exception.');
        } catch (CnpjLookupException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('O serviço de consulta de CNPJ está indisponível.', $exception->getMessage());
        }
    }

    public function test_it_allows_three_distinct_cache_misses_and_rejects_the_fourth_for_the_global_window(): void
    {
        Http::fake([
            'publica.cnpj.ws/cnpj/*' => Http::response($this->providerPayload()),
        ]);

        $service = resolve(CnpjWsLookup::class);
        $service->lookup('27865757000102');
        $service->lookup('04252011000110');
        $service->lookup('00623904000173');

        try {
            $service->lookup('11222333000181');
            $this->fail('The fourth distinct cache miss should be rate limited.');
        } catch (CnpjLookupException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertSame('Limite temporário de consultas atingido. Tente novamente em um minuto.', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_failed_outbound_attempts_consume_the_global_rate_limit_quota(): void
    {
        Http::fake([
            'publica.cnpj.ws/cnpj/*' => Http::response([], 500),
        ]);

        $service = resolve(CnpjWsLookup::class);

        foreach (['27865757000102', '04252011000110', '00623904000173'] as $cnpj) {
            try {
                $service->lookup($cnpj);
                $this->fail('A provider server error should throw a lookup exception.');
            } catch (CnpjLookupException $exception) {
                $this->assertSame(503, $exception->status);
            }
        }

        try {
            $service->lookup('11222333000181');
            $this->fail('The fourth failed cache miss should be rate limited.');
        } catch (CnpjLookupException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertSame('Limite temporário de consultas atingido. Tente novamente em um minuto.', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_lookup_preview_returns_official_data_without_mutation(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response($this->providerPayload())]);
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->postJson('/api/clients/cnpj-lookup', ['tax_id' => '27.865.757/0001-02'])
            ->assertOk()
            ->assertJsonPath('data.tax_id', '27865757000102')
            ->assertJsonPath('data.name', 'GLOBO COMUNICACAO E PARTICIPACOES S/A')
            ->assertJsonMissingPath('data.socios');

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_lookup_preview_rejects_invalid_cnpj(): void
    {
        Http::fake();
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->postJson('/api/clients/cnpj-lookup', ['tax_id' => '27.865.757/0001-03'])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id');

        Http::assertSentCount(0);
    }

    public function test_lookup_preview_maps_provider_not_found(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response([], 404)]);
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->postJson('/api/clients/cnpj-lookup', ['tax_id' => '27865757000102'])
            ->assertNotFound()->assertJsonPath('message', 'CNPJ não encontrado.');
    }

    public function test_consulta_de_alfanumerico_diz_que_nao_conhece_sem_gastar_consulta(): void
    {
        // A tela não oferece esta consulta para documento alfanumérico, mas a
        // validação aceita o CNPJ alfanumérico e a API é pública para quem a
        // chama: sem o atalho, um documento que a fonte não tem como responder
        // gastava uma das três consultas por minuto da conta para descobrir o que
        // já se sabia.
        Http::fake(['publica.cnpj.ws/*' => Http::response([], 404)]);
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->postJson('/api/clients/cnpj-lookup', ['tax_id' => '12.ABC.345/0001-88'])
            ->assertNotFound()
            ->assertJsonPath('message', 'CNPJ não encontrado.');

        Http::assertNothingSent();
    }

    public function test_user_role_cannot_use_lookup_or_refresh(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'user'), 'sanctum');

        $this->postJson('/api/clients/cnpj-lookup', ['tax_id' => '27865757000102'])->assertForbidden();
        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh-preview")->assertForbidden();
        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh")->assertForbidden();
    }

    public function test_refresh_preview_reports_changes_without_mutating(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response($this->providerPayload())]);
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '27865757000102',
            'trade_name' => 'Nome Antigo',
        ]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $response = $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh-preview")
            ->assertOk()->assertJsonPath('data.incoming.trade_name', 'GLOBOPLAY')
            ->assertJsonPath('data.current.trade_name', 'Nome Antigo');

        $this->assertArrayHasKey('trade_name', $response->json('data.changes'));
        $this->assertSame('Nome Antigo', Client::withoutGlobalScopes()->find($client->getKey())->trade_name);
    }

    public function test_refresh_update_applies_official_fields(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response($this->providerPayload())]);
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '27865757000102',
            'trade_name' => 'Nome Antigo',
        ]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh", [])
            ->assertOk()->assertJsonPath('data.trade_name', 'GLOBOPLAY');

        $this->assertSame('GLOBOPLAY', Client::withoutGlobalScopes()->find($client->getKey())->trade_name);
    }

    public function test_refresh_rejects_individual_and_preserves_data(): void
    {
        Http::fake();
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh-preview")
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh", [])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id');

        Http::assertSentCount(0);
    }

    public function test_refresh_failure_preserves_stored_data(): void
    {
        Http::fake(['publica.cnpj.ws/*' => Http::response([], 500)]);
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '27865757000102',
            'trade_name' => 'Nome Antigo',
        ]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh", [])->assertStatus(503);

        $this->assertSame('Nome Antigo', Client::withoutGlobalScopes()->find($client->getKey())->trade_name);
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPayload(): array
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
}
