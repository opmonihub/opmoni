<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\ClientEcacPowerOfAttorney;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientEcacPowerOfAttorneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
    }

    public function test_operador_upserts_power_of_attorney_and_receives_deadline_status(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        $account = Account::factory()->create();
        $operator = $this->memberOf($account, 'operador');
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($operator, 'sanctum')->putJson(
            "/api/clients/{$client->getKey()}/ecac-power-of-attorney",
            ['starts_at' => '2026-01-01', 'expires_at' => '2026-10-10', 'notes' => 'Todos os serviços']
        )->assertOk()
            ->assertJsonPath('data.ecac_power_of_attorney.status', 'expiring');

        CarbonImmutable::setTestNow();
    }

    public function test_incoherent_dates_return_422_and_preserve_record(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $client->getKey(),
            'starts_at' => '2026-01-01', 'expires_at' => '2026-12-31',
        ]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->putJson("/api/clients/{$client->getKey()}/ecac-power-of-attorney",
            ['starts_at' => '2026-12-01', 'expires_at' => '2026-01-01']
        )->assertUnprocessable()->assertJsonValidationErrors('expires_at');

        $this->assertSame('2026-12-31', ClientEcacPowerOfAttorney::sole()->expires_at->toDateString());
    }

    public function test_user_cannot_write_power_of_attorney(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'user'), 'sanctum');

        $this->putJson("/api/clients/{$client->getKey()}/ecac-power-of-attorney",
            ['starts_at' => '2026-01-01', 'expires_at' => '2026-12-31'])->assertForbidden();
        $this->deleteJson("/api/clients/{$client->getKey()}/ecac-power-of-attorney")->assertForbidden();
    }

    public function test_cross_account_power_of_attorney_is_404(): void
    {
        $client = Client::factory()->company()->create();
        $this->actingAs($this->memberOf(Account::factory()->create(), 'operador'), 'sanctum');

        $this->putJson("/api/clients/{$client->getKey()}/ecac-power-of-attorney",
            ['starts_at' => '2026-01-01', 'expires_at' => '2026-12-31'])->assertNotFound();
        $this->deleteJson("/api/clients/{$client->getKey()}/ecac-power-of-attorney")->assertNotFound();
    }

    public function test_operador_registra_codigo_serpro_e_o_estado_fica_pendente(): void
    {
        $account = Account::factory()->create();
        $operator = $this->memberOf($account, 'operador');
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($operator, 'sanctum')->putJson(
            "/api/clients/{$client->getKey()}/ecac-power-of-attorney",
            ['starts_at' => '2026-09-01', 'expires_at' => '2027-09-01', 'serpro_code' => '00146']
        )->assertOk()
            ->assertJsonPath('data.ecac_power_of_attorney.serpro_code', '00146')
            ->assertJsonPath('data.ecac_power_of_attorney.integration_state', 'pending');

        // O código registrado pelo Membro marca a procuração como pendente: a
        // confirmação é do provedor, e nenhuma escrita local pode afirmar que
        // ela está estabelecida.
        $this->assertDatabaseHas('client_ecac_powers_of_attorney', [
            'client_id' => $client->getKey(),
            'serpro_code' => '00146',
            'integration_state' => 'pending',
        ]);
    }

    public function test_put_sem_codigo_preserva_codigo_e_estado_anteriores(): void
    {
        $account = Account::factory()->create();
        $operator = $this->memberOf($account, 'operador');
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $power = ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'serpro_code' => '00146',
        ]);
        // O estado `established` não pode nascer de um save — o `saving` do
        // model volta para `pending` ao ver o código mudar — porque no produto
        // quem o escreve é o oracle lendo o provedor. A fixture precisa do
        // mesmo atalho silencioso.
        $power->forceFill(['integration_state' => 'established'])->saveQuietly();
        $this->assertSame('established', $power->fresh()->integration_state->value);

        $this->actingAs($operator, 'sanctum')->putJson(
            "/api/clients/{$client->getKey()}/ecac-power-of-attorney",
            ['starts_at' => '2027-01-01', 'expires_at' => '2028-01-01']
        )->assertOk()
            ->assertJsonPath('data.ecac_power_of_attorney.serpro_code', '00146')
            ->assertJsonPath('data.ecac_power_of_attorney.integration_state', 'established');
    }

    public function test_trocar_o_codigo_volta_a_marcar_pendente(): void
    {
        $account = Account::factory()->create();
        $operator = $this->memberOf($account, 'operador');
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $power = ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'serpro_code' => '00006',
        ]);
        $power->forceFill(['integration_state' => 'established'])->saveQuietly();

        $this->actingAs($operator, 'sanctum')->putJson(
            "/api/clients/{$client->getKey()}/ecac-power-of-attorney",
            ['starts_at' => '2026-01-01', 'expires_at' => '2027-01-01', 'serpro_code' => '00146']
        )->assertOk()
            ->assertJsonPath('data.ecac_power_of_attorney.integration_state', 'pending');
    }

    public function test_procuracao_exposta_sem_caminho_nem_segredo(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'serpro_code' => '00146',
            'integration_state' => 'pending',
        ]);

        $response = $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson("/api/clients/{$client->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.ecac_power_of_attorney.serpro_code', '00146')
            ->assertJsonPath('data.ecac_power_of_attorney.integration_state', 'pending');

        // Datas, código e estado entram; caminho de arquivo, credencial e
        // qualquer material de armazenamento, não.
        $power = $response->json('data.ecac_power_of_attorney');
        $this->assertSame(
            ['id', 'starts_at', 'expires_at', 'notes', 'status', 'serpro_code', 'integration_state'],
            array_keys($power)
        );
    }

    public function test_destroy_returns_204_and_removes_metadata(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $client->getKey(),
        ]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->deleteJson("/api/clients/{$client->getKey()}/ecac-power-of-attorney")->assertNoContent();
        $this->assertDatabaseCount('client_ecac_powers_of_attorney', 0);
    }

    public function test_index_supports_deadline_status_filter(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        $account = Account::factory()->create();
        $missing = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $valid = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $expiring = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $expired = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        ClientEcacPowerOfAttorney::factory()->create(['account_id' => $account->getKey(),
            'client_id' => $valid->getKey(), 'starts_at' => '2026-01-01', 'expires_at' => '2027-06-01']);
        ClientEcacPowerOfAttorney::factory()->create(['account_id' => $account->getKey(),
            'client_id' => $expiring->getKey(), 'starts_at' => '2026-01-01', 'expires_at' => '2026-10-10']);
        ClientEcacPowerOfAttorney::factory()->create(['account_id' => $account->getKey(),
            'client_id' => $expired->getKey(), 'starts_at' => '2026-01-01', 'expires_at' => '2026-09-10']);
        foreach (['valid' => '2027-06-01', 'expiring' => '2026-10-10', 'expired' => '2026-09-10'] as $key => $date) {
            $target = ${$key};
            ClientCertificate::factory()->create(['account_id' => $account->getKey(),
                'client_id' => $target->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => $date]);
        }
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->getJson('/api/clients?deadline_status=missing')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $missing->getKey());
        $this->getJson('/api/clients?deadline_status=valid')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $valid->getKey());
        $this->getJson('/api/clients?deadline_status=expiring')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $expiring->getKey());
        $this->getJson('/api/clients?deadline_status=expired')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $expired->getKey());
        $this->getJson('/api/clients?deadline_status=bogus')->assertUnprocessable()
            ->assertJsonValidationErrors('deadline_status');

        CarbonImmutable::setTestNow();
    }

    public function test_index_supports_portfolio_view_filters(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        $account = Account::factory()->create();
        $plain = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Sem documento']);
        $expiring = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Certificado a vencer']);
        $valid = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Certificado válido']);
        $expired = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Certificado vencido']);
        $poa = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Só procuração']);
        ClientCertificate::factory()->create(['account_id' => $account->getKey(), 'client_id' => $expiring->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => '2026-10-10']);
        ClientCertificate::factory()->create(['account_id' => $account->getKey(), 'client_id' => $valid->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => '2027-06-01']);
        ClientCertificate::factory()->create(['account_id' => $account->getKey(), 'client_id' => $expired->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => '2026-09-10']);
        ClientEcacPowerOfAttorney::factory()->create(['account_id' => $account->getKey(), 'client_id' => $poa->getKey(), 'starts_at' => '2026-01-01', 'expires_at' => '2027-06-01']);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $ids = fn (string $view): array => $this->getJson("/api/clients?view={$view}")->assertOk()->json('data.*.id');

        $this->assertEqualsCanonicalizing([$plain->getKey(), $poa->getKey()], $ids('certificate_missing'));
        $this->assertEqualsCanonicalizing([$valid->getKey()], $ids('certificate_valid'));
        $this->assertEqualsCanonicalizing([$expiring->getKey()], $ids('certificate_expiring'));
        $this->assertEqualsCanonicalizing([$expired->getKey()], $ids('certificate_expired'));
        $this->assertEqualsCanonicalizing([$poa->getKey()], $ids('poa_valid'));
        $this->assertEqualsCanonicalizing([], $ids('poa_expiring'));
        $this->assertEqualsCanonicalizing([], $ids('poa_expired'));
        $this->assertEqualsCanonicalizing(
            [$plain->getKey(), $expiring->getKey(), $valid->getKey(), $expired->getKey()],
            $ids('poa_missing')
        );
        $this->getJson('/api/clients/summary')->assertOk()->assertJsonPath('data', [
            'total' => 5,
            'active' => 5,
            'inactive' => 0,
            'certificate' => ['missing' => 2, 'valid' => 1, 'expiring' => 1, 'expired' => 1],
            'poa' => ['missing' => 4, 'valid' => 1, 'expiring' => 0, 'expired' => 0],
        ]);
        $this->getJson('/api/clients?view=bogus')->assertUnprocessable()->assertJsonValidationErrors('view');

        CarbonImmutable::setTestNow();
    }

    public function test_client_responses_include_fiscal_statuses_without_n_plus_one(): void
    {
        $account = Account::factory()->create();
        $clients = Client::factory()->individual()->count(50)->create(['account_id' => $account->getKey()]);
        foreach ($clients->take(6) as $client) {
            ClientEcacPowerOfAttorney::factory()->create([
                'account_id' => $account->getKey(), 'client_id' => $client->getKey(),
            ]);
        }
        $operator = $this->memberOf($account, 'operador');
        $operator->accountRole($account->getKey());
        $this->actingAs($operator, 'sanctum');

        $countFor = function (int $perPage): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/clients?per_page={$perPage}")->assertOk()
                ->assertJsonPath('data.0.certificate_status', 'missing')
                ->assertJsonStructure(['data' => [['certificate_status', 'ecac_power_of_attorney_status']]]);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($countFor(25), $countFor(50));
    }

    public function test_index_filters_columns_and_sorts_certificate_and_power_of_attorney(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        $account = Account::factory()->create();
        $early = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Beta']);
        $late = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Alpha']);
        $missing = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Zulu']);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $early->getKey(),
            'valid_from' => '2026-01-01', 'valid_until' => '2026-09-01',
        ]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $late->getKey(),
            'valid_from' => '2026-01-01', 'valid_until' => '2027-06-01',
        ]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $missing->getKey(),
            'valid_from' => '2020-01-01', 'valid_until' => '2020-06-01', 'replaced_at' => '2026-01-01',
        ]);
        ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $early->getKey(),
            'starts_at' => '2026-01-01', 'expires_at' => '2026-08-01',
        ]);
        ClientEcacPowerOfAttorney::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $late->getKey(),
            'starts_at' => '2026-01-01', 'expires_at' => '2028-01-01',
        ]);
        $tag = Tag::factory()->create(['account_id' => $account->getKey(), 'name' => 'Prioridade']);
        $early->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);
        $foreign = Tag::factory()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients?sort=certificate&direction=asc')->assertOk()
            ->assertJsonPath('data.0.id', $early->getKey())
            ->assertJsonPath('data.1.id', $late->getKey())
            ->assertJsonPath('data.2.id', $missing->getKey());
        $this->getJson('/api/clients?sort=certificate&direction=desc')->assertOk()
            ->assertJsonPath('data.0.id', $late->getKey())
            ->assertJsonPath('data.1.id', $early->getKey())
            ->assertJsonPath('data.2.id', $missing->getKey());
        $this->getJson('/api/clients?sort=poa&direction=asc')->assertOk()
            ->assertJsonPath('data.0.id', $early->getKey())
            ->assertJsonPath('data.1.id', $late->getKey())
            ->assertJsonPath('data.2.id', $missing->getKey());
        $this->getJson('/api/clients?certificate_status=missing')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $missing->getKey());
        $this->getJson('/api/clients?poa_status=valid')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $late->getKey());
        $this->getJson('/api/clients?tag_id='.$tag->getKey())->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $early->getKey());
        $this->getJson('/api/clients?tag_id='.$foreign->getKey())->assertUnprocessable()
            ->assertJsonValidationErrors('tag_id');
        $this->getJson('/api/clients?sort=valid_until')->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        CarbonImmutable::setTestNow();
    }

    public function test_selection_snapshot_follows_the_portfolio_view_inside_the_tenant(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Sem']);
        $valid = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Com']);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $valid->getKey(),
            'valid_from' => '2026-01-01', 'valid_until' => '2027-06-01',
        ]);
        Client::factory()->individual()->create(['account_id' => $other->getKey(), 'name' => 'Alheio']);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->postJson('/api/clients/selections', ['view' => 'certificate_missing'])->assertOk()
            ->assertJsonPath('data.count', 1);
        $this->postJson('/api/clients/selections', ['view' => 'certificate_valid'])->assertOk()
            ->assertJsonPath('data.count', 1);

        CarbonImmutable::setTestNow();
    }

    public function test_client_soft_delete_removes_active_ciphertext(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $certificate = ClientCertificate::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $client->getKey(),
            'storage_path' => 'cipher/active.enc',
        ]);
        Storage::disk('certificates')->put('cipher/active.enc', 'secret-bytes');
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->deleteJson("/api/clients/{$client->getKey()}")->assertNoContent();

        $this->assertSoftDeleted('clients', ['id' => $client->getKey()]);
        $this->assertFalse(Storage::disk('certificates')->exists('cipher/active.enc'));
        $this->assertNotNull($certificate->refresh()->removed_at);
        $this->assertNull($certificate->refresh()->storage_path);
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
