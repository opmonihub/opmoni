<?php

namespace Tests\Feature\Serpro;

use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\SerproClientAuthorization;
use App\Models\SerproMonitoring;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A procuração e-CAC derivada: a menor validade entre as famílias que os
 * módulos associados exigem — ou, sem módulo que exija família, as famílias
 * que o provedor confirmou para o cliente. Nenhum dado digitado participa.
 */
class ClientDerivedPowerOfAttorneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_todas_as_familias_exigidas_em_vigor_dao_valid_com_a_menor_data(): void
    {
        $account = Account::factory()->create();
        $client = $this->clienteComModulo($account, 'declaracoes/pgdas', '00146');
        $this->autorizacao($account, $client, '00146', '2027-06-01');
        // Uma família a mais confirmada que o módulo não exige não muda nada:
        // a menor validade é das exigidas.
        $this->autorizacao($account, $client, '00002', '2026-10-20');

        $power = $this->procuracao($account, $client);

        $this->assertSame('valid', $power['status']);
        $this->assertSame('2027-06-01', $power['expires_on']);
        $this->assertSame(['00146'], array_column($power['families'], 'family'));
        $this->assertSame(
            [['family' => '00146', 'state' => 'established', 'expires_on' => '2027-06-01']],
            $power['families'],
        );
        $this->assertSame('valid', $this->statusDo($account, $client));
    }

    public function test_familia_exigida_sem_confirmacao_e_missing_e_nomeia_a_que_falta(): void
    {
        $account = Account::factory()->create();
        // `00076+00188` é conjunção: as duas precisam estar estabelecidas.
        $client = $this->clienteComModulo($account, 'parcelamentos/simples-nacional', '00076+00188');
        $this->autorizacao($account, $client, '00076', '2027-01-01');

        $power = $this->procuracao($account, $client);

        $this->assertSame('missing', $power['status']);
        $this->assertNull($power['expires_on']);
        $this->assertSame(
            [['family' => '00188', 'state' => 'missing', 'expires_on' => null]],
            $power['families'],
        );
    }

    public function test_alternativa_vale_pela_melhor_coberta(): void
    {
        $account = Account::factory()->create();
        // `'00149+10011, 00210+10036'` são duas alternativas: uma inteira basta.
        $client = $this->clienteComModulo($account, 'parcelamentos/receita-federal', '00149+10011, 00210+10036');
        $this->autorizacao($account, $client, '00149', '2027-01-01');
        $this->autorizacao($account, $client, '10011', '2027-03-01');

        $power = $this->procuracao($account, $client);

        $this->assertSame('valid', $power['status']);
        $this->assertSame('2027-01-01', $power['expires_on']);
    }

    public function test_alternativa_posterior_coberta_vence_a_primeira_faltante(): void
    {
        $account = Account::factory()->create();
        // `'00149+10011, 00210+10036'` são duas alternativas: só a segunda
        // está outorgada, então o resumo vale por ela.
        $client = $this->clienteComModulo($account, 'parcelamentos/receita-federal', '00149+10011, 00210+10036');
        $this->autorizacao($account, $client, '00210', '2027-01-01');
        $this->autorizacao($account, $client, '10036', '2027-03-01');

        $power = $this->procuracao($account, $client);

        $this->assertSame('valid', $power['status']);
        $this->assertSame('2027-01-01', $power['expires_on']);
        $this->assertSame(['00210', '10036'], array_column($power['families'], 'family'));
    }

    public function test_familia_vencida_derruba_para_expired_com_a_data(): void
    {
        $account = Account::factory()->create();
        $client = $this->clienteComModulo($account, 'caixas-postais/e-cac', '00006');
        $this->autorizacao($account, $client, '00006', '2026-09-01');

        $power = $this->procuracao($account, $client);

        $this->assertSame('expired', $power['status']);
        $this->assertSame('2026-09-01', $power['expires_on']);
    }

    public function test_expired_vence_missing_e_missing_vence_expiring(): void
    {
        $account = Account::factory()->create();
        $client = $this->clienteComModulo($account, 'parcelamentos/simples-nacional', '00076+00188');
        $this->modulo($account, $client, 'caixas-postais/e-cac', '00006');
        // `00076` vencida + `00188` ausente + `00006` a vencer: a pior das
        // três leituras é `expired`, e não `missing` nem `expiring`.
        $this->autorizacao($account, $client, '00076', '2026-09-01');
        $this->autorizacao($account, $client, '00006', '2026-10-20');

        $power = $this->procuracao($account, $client);

        $this->assertSame('expired', $power['status']);
    }

    public function test_fallback_sem_modulo_usa_as_familias_sincronizadas(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->autorizacao($account, $client, '00006', '2027-05-01');
        $this->autorizacao($account, $client, '00002', '2027-01-01');

        $power = $this->procuracao($account, $client);

        // A menor validade das confirmadas, todas listadas.
        $this->assertSame('valid', $power['status']);
        $this->assertSame('2027-01-01', $power['expires_on']);
        $this->assertSame(['00002', '00006'], array_column($power['families'], 'family'));
    }

    public function test_sem_modulo_e_sem_familia_e_missing(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $power = $this->procuracao($account, $client);

        $this->assertSame('missing', $power['status']);
        $this->assertNull($power['expires_on']);
        $this->assertSame([], $power['families']);
    }

    public function test_a_resposta_nao_traz_codigo_notas_nem_caminho(): void
    {
        $account = Account::factory()->create();
        $client = $this->clienteComModulo($account, 'declaracoes/pgdas', '00146');
        $this->autorizacao($account, $client, '00146', '2027-01-01');

        $power = $this->procuracao($account, $client);

        // A forma nova: estado, a menor validade e as famílias. Nenhum campo
        // de código SERPRO, de nota ou de caminho de armazenamento.
        $this->assertSame(['status', 'expires_on', 'families'], array_keys($power));
        foreach ($power['families'] as $family) {
            $this->assertSame(['family', 'state', 'expires_on'], array_keys($family));
        }
    }

    public function test_filtro_e_ordenacao_pela_menor_validade_estabelecida(): void
    {
        $account = Account::factory()->create();
        $valido = Client::factory()->company()->create(['account_id' => $account->getKey(), 'name' => 'Valido']);
        $aVencer = Client::factory()->company()->create(['account_id' => $account->getKey(), 'name' => 'A Vencer']);
        $vencido = Client::factory()->company()->create(['account_id' => $account->getKey(), 'name' => 'Vencido']);
        $sem = Client::factory()->company()->create(['account_id' => $account->getKey(), 'name' => 'Sem']);
        $this->autorizacao($account, $valido, '00006', '2027-06-01');
        $this->autorizacao($account, $aVencer, '00006', '2026-10-20');
        $this->autorizacao($account, $vencido, '00006', '2026-09-01');
        // Linha recusada não conta para a data do filtro: uma outorga que o
        // provedor retirou não sustenta o status.
        $this->autorizacao($account, $vencido, '00002', '2027-12-31', SerproPowerOfAttorneyState::Rejected);

        $member = $this->memberOf($account, 'operador');

        $ids = fn (string $query): array => collect(
            $this->actingAs($member, 'sanctum')->getJson('/api/clients?'.$query)->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertSame([$vencido->getKey()], $ids('poa_status=expired'));
        $this->assertSame([$aVencer->getKey()], $ids('poa_status=expiring'));
        $this->assertSame([$valido->getKey()], $ids('poa_status=valid'));
        $this->assertSame([$sem->getKey()], $ids('poa_status=missing'));

        // Menor validade primeiro; os sem família confirmada por último.
        $this->assertSame(
            [$vencido->getKey(), $aVencer->getKey(), $valido->getKey(), $sem->getKey()],
            $ids('sort=poa&direction=asc'),
        );
        $this->assertSame(
            [$valido->getKey(), $aVencer->getKey(), $vencido->getKey(), $sem->getKey()],
            $ids('sort=poa&direction=desc'),
        );
    }

    public function test_as_respostas_da_lista_nao_custam_uma_consulta_por_cliente(): void
    {
        $account = Account::factory()->create();
        $clients = Client::factory()->company()->count(20)->create(['account_id' => $account->getKey()]);
        foreach ($clients->take(5) as $client) {
            $this->autorizacao($account, $client, '00006', '2027-06-01');
            $this->modulo($account, $client, 'caixas-postais/e-cac', '00006');
        }
        $member = $this->memberOf($account, 'operador');

        // A primeira resposta paga o aquecimento do papel do membro — que é
        // consulta única de autenticação, não por cliente. Ela sai antes da
        // medição, senão o delta dela pareceria N+1.
        $this->actingAs($member, 'sanctum')->getJson('/api/clients?per_page=25')->assertOk();

        $countFor = function (int $perPage) use ($member): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($member, 'sanctum')
                ->getJson("/api/clients?per_page={$perPage}")
                ->assertOk()
                ->assertJsonStructure(['data' => [['ecac_power_of_attorney_status']]]);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($countFor(25), $countFor(100));
    }

    public function test_o_status_do_resumo_e_o_da_coluna_na_listagem_e_no_show(): void
    {
        $account = Account::factory()->create();
        $client = $this->clienteComModulo($account, 'caixas-postais/e-cac', '00006');
        $this->autorizacao($account, $client, '00006', '2026-10-20');

        $member = $this->memberOf($account, 'operador');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('data.0.ecac_power_of_attorney_status', 'expiring')
            ->assertJsonPath('data.0.ecac_power_of_attorney.status', 'expiring')
            ->assertJsonPath('data.0.ecac_power_of_attorney.expires_on', '2026-10-20');

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/clients/{$client->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.ecac_power_of_attorney_status', 'expiring')
            ->assertJsonPath('data.ecac_power_of_attorney.status', 'expiring');
    }

    public function test_filtros_contadores_e_atencao_usam_o_resumo_das_familias_exigidas(): void
    {
        $account = Account::factory()->create();
        $valid = $this->clienteComModulo($account, 'declaracoes/pgdas', '00146');
        $this->autorizacao($account, $valid, '00146', '2027-06-01');
        $this->autorizacao($account, $valid, '00002', '2026-10-20');
        $missing = $this->clienteComModulo($account, 'parcelamentos/simples-nacional', '00076+00188');
        $this->autorizacao($account, $missing, '00076', '2027-01-01');
        $expired = $this->clienteComModulo($account, 'caixas-postais/e-cac', '00006');
        $this->autorizacao($account, $expired, '00006', '2026-09-01', SerproPowerOfAttorneyState::Expired);
        foreach ([$valid, $missing, $expired] as $client) {
            ClientCertificate::factory()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
                'valid_until' => '2027-06-01',
            ]);
        }
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        foreach (['poa_status', 'deadline_status'] as $filter) {
            $this->getJson("/api/clients?{$filter}=expired")
                ->assertOk()->assertJsonPath('data.*.id', [$expired->getKey()]);
            $this->getJson("/api/clients?{$filter}=missing")
                ->assertOk()->assertJsonPath('data.*.id', [$missing->getKey()]);
            $this->getJson("/api/clients?{$filter}=expiring")
                ->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson('/api/clients?poa_status=valid')
            ->assertOk()->assertJsonPath('data.*.id', [$valid->getKey()]);
        $this->getJson('/api/clients?view=poa_expired')
            ->assertOk()->assertJsonPath('data.*.id', [$expired->getKey()]);
        $this->getJson('/api/clients?poa_status[]=expired&poa_status[]=missing')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/clients/summary')
            ->assertOk()->assertJsonPath('data.poa', [
                'missing' => 1, 'valid' => 1, 'expiring' => 0, 'expired' => 1,
            ]);
        $this->getJson('/api/clients/analytics')
            ->assertOk()->assertJsonPath('data.attention.poa', [[
                'id' => $expired->getKey(),
                'name' => $expired->name,
                'tax_id' => $expired->tax_id,
                'status' => 'expired',
                'expires_at' => '2026-09-01',
            ]]);
    }

    public function test_ordenacao_usa_a_data_publicada_e_deixa_resumos_sem_data_no_fim(): void
    {
        $account = Account::factory()->create();
        $later = $this->clienteComModulo($account, 'declaracoes/pgdas', '00146');
        $this->autorizacao($account, $later, '00146', '2027-06-01');
        $this->autorizacao($account, $later, '00002', '2026-10-01');
        $earlier = $this->clienteComModulo($account, 'caixas-postais/e-cac', '00006');
        $this->autorizacao($account, $earlier, '00006', '2027-01-01');
        $missing = $this->clienteComModulo($account, 'parcelamentos/simples-nacional', '00076+00188');
        $this->autorizacao($account, $missing, '00076', '2026-10-10');
        $expired = $this->clienteComModulo($account, 'caixas-postais/e-cac', '00006');
        $this->autorizacao($account, $expired, '00006', '2026-09-01', SerproPowerOfAttorneyState::Expired);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->getJson('/api/clients?sort=poa&direction=asc')
            ->assertOk()->assertJsonPath('data.*.id', [
                $expired->getKey(), $earlier->getKey(), $later->getKey(), $missing->getKey(),
            ]);
        $this->getJson('/api/clients?sort=poa&direction=desc&sheet=1')
            ->assertOk()->assertJsonPath('data.*.id', [
                $later->getKey(), $earlier->getKey(), $expired->getKey(), $missing->getKey(),
            ]);
    }

    public function test_familias_recusadas_e_pendentes_continuam_nomeadas_no_resumo_missing(): void
    {
        $account = Account::factory()->create();
        foreach ([SerproPowerOfAttorneyState::Rejected, SerproPowerOfAttorneyState::Pending] as $state) {
            $client = $this->clienteComModulo($account, 'caixas-postais/e-cac', '00006');
            $this->autorizacao($account, $client, '00006', '2027-01-01', $state);

            $this->assertSame([
                'status' => 'missing', 'expires_on' => null,
                'families' => [['family' => '00006', 'state' => $state->value, 'expires_on' => '2027-01-01']],
            ], $this->procuracao($account, $client));
        }
    }

    public function test_sem_modulo_autorizacao_recusada_nao_publica_data_nem_intercala_a_ordenacao(): void
    {
        $account = Account::factory()->create();
        $valid = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->autorizacao($account, $valid, '00006', '2027-06-01');
        $unconfirmed = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->autorizacao($account, $unconfirmed, '00006', '2026-10-01', SerproPowerOfAttorneyState::Rejected);

        $power = $this->procuracao($account, $unconfirmed);
        $this->assertSame('missing', $power['status']);
        $this->assertNull($power['expires_on']);
        foreach (['asc', 'desc'] as $direction) {
            $this->getJson("/api/clients?sort=poa&direction={$direction}")
                ->assertOk()->assertJsonPath('data.*.id', [$valid->getKey(), $unconfirmed->getKey()]);
        }
    }

    public function test_editar_email_e_status_preserva_a_procuracao_da_resposta(): void
    {
        $account = Account::factory()->create();
        $client = $this->clienteComModulo($account, 'declaracoes/pgdas', '00146');
        $this->autorizacao($account, $client, '00146', '2027-06-01');
        $expected = $this->procuracao($account, $client);

        foreach ([['email' => 'contato@example.com'], ['status' => 'inactive']] as $payload) {
            $this->patchJson("/api/clients/{$client->getKey()}", $payload)
                ->assertOk()->assertJsonPath('data.ecac_power_of_attorney', $expected)
                ->assertJsonPath('data.ecac_power_of_attorney_status', 'valid');
        }
    }

    public function test_atualizar_cnpj_preserva_a_procuracao_da_resposta(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $account = Account::factory()->create();
        $client = $this->clienteComModulo($account, 'declaracoes/pgdas', '00146');
        $client->forceFill(['tax_id' => '27865757000102', 'tax_regime' => 'presumed_profit'])->saveQuietly();
        $this->autorizacao($account, $client, '00146', '2027-06-01');
        $expected = $this->procuracao($account, $client);
        Http::fake(['publica.cnpj.ws/*' => Http::response([
            'razao_social' => 'Empresa atualizada',
            'simples' => ['mei' => 'Não', 'simples' => 'Não'],
            'estabelecimento' => ['cnpj' => $client->tax_id, 'atualizado_em' => '2026-09-28T10:00:00Z'],
        ])]);

        $this->postJson("/api/clients/{$client->getKey()}/cnpj-refresh")
            ->assertOk()->assertJsonPath('data.name', 'Empresa atualizada')
            ->assertJsonPath('data.ecac_power_of_attorney', $expected)
            ->assertJsonPath('data.ecac_power_of_attorney_status', 'valid');
    }

    /**
     * Cliente com o vínculo `client × obrigação` gravado, sem fonte — como o
     * `associate` o deixa antes da próxima execução.
     */
    private function clienteComModulo(Account $account, string $obligation, string $procuracao): Client
    {
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->modulo($account, $client, $obligation, $procuracao);

        return $client;
    }

    private function modulo(Account $account, Client $client, string $obligation, string $procuracao): void
    {
        // A `procuracao` lida é a do catálogo: o teste declara a que o config
        // tem para a obrigação, e a divergência entre os dois falharia na
        // leitura, não aqui.
        $this->assertSame($procuracao, config("integra-contador.obligations.{$obligation}.procuracao"));

        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => $obligation,
            'source_at' => null,
        ]);
    }

    private function autorizacao(
        Account $account,
        Client $client,
        string $family,
        ?string $expiresOn,
        SerproPowerOfAttorneyState $state = SerproPowerOfAttorneyState::Established,
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

    /**
     * @return array{status: string, expires_on: ?string, families: list<array{family: string, state: string, expires_on: ?string}>}
     */
    private function procuracao(Account $account, Client $client): array
    {
        return $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->getJson("/api/clients/{$client->getKey()}")
            ->assertOk()
            ->json('data.ecac_power_of_attorney');
    }

    private function statusDo(Account $account, Client $client): string
    {
        return $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->getJson("/api/clients/{$client->getKey()}")
            ->assertOk()
            ->json('data.ecac_power_of_attorney_status');
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
