<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

class SerproMonitoringDeclarationApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;
        parent::tearDown();
    }

    public function test_detalhe_devolve_periodos_sincronizados(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $periods = [[
            'period' => '2026-01',
            'declared_at' => '2026-01-31T10:00:00Z',
            'declaration_number' => '00000000202601001',
            'rectified' => false,
            'slip_number' => '07202215764027873',
            'slip_issued_at' => '2026-02-01T09:00:00Z',
            'due_on' => null,
            'slip_paid' => true,
        ]];

        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'periods' => $periods,
        ]);

        $this->actingAs($this->memberOf($account, 'user'), 'web')
            ->getJson("/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$client->getKey()}/declaration-detail")
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->getKey())
            ->assertJsonPath('data.periods.0.period', '2026-01')
            ->assertJsonPath('data.periods.0.declaration_number', '00000000202601001');
    }

    public function test_detalhe_responde_404_para_obrigacao_sem_suporte(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'user'), 'web')
            ->getJson("/api/v1/serpro/monitoring/obligations/simples-nacional/clients/{$client->getKey()}/declaration-detail")
            ->assertNotFound();
    }

    public function test_download_recusa_user_sem_papel_de_escrita(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'periods' => [[
                'period' => '2026-01',
                'declared_at' => '2026-01-31T10:00:00Z',
                'declaration_number' => '00000000202601001',
                'rectified' => false,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
            ]],
        ]);

        $this->actingAs($this->memberOf($account, 'user'), 'web')
            ->get("/api/v1/serpro/monitoring/obligations/declaracoes/pgdas/clients/{$client->getKey()}/periods/2026-01/artifacts/recibo")
            ->assertForbidden();
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
