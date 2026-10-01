<?php

namespace Tests\Feature\Tenancy;

use App\Enums\ClientStatus;
use App\Enums\SerproPowerOfAttorneyState;
use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\SerproClientAuthorization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPortfolioAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_includes_active_and_inactive_counts(): void
    {
        $account = Account::factory()->create();
        Client::factory()->individual()->count(3)->create([
            'account_id' => $account->getKey(),
            'status' => ClientStatus::Active,
        ]);
        Client::factory()->individual()->count(2)->create([
            'account_id' => $account->getKey(),
            'status' => ClientStatus::Inactive,
        ]);
        Client::factory()->individual()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients/summary')->assertOk()->assertJsonPath('data', [
            'total' => 5,
            'active' => 3,
            'inactive' => 2,
            'certificate' => ['missing' => 5, 'valid' => 0, 'expiring' => 0, 'expired' => 0],
            'poa' => ['missing' => 5, 'valid' => 0, 'expiring' => 0, 'expired' => 0],
        ]);
    }

    public function test_summary_and_analytics_filter_by_client_id(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        $account = Account::factory()->create();
        $sp = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => 'Empresa SP',
            'state' => 'SP',
            'city' => 'São Paulo',
            'tax_regime' => TaxRegime::SimpleNational,
            'legal_nature' => 'Sociedade Empresária Limitada',
            'primary_activity_code' => '6201-5/01',
            'primary_activity_description' => 'Desenvolvimento de software',
        ]);
        $rj = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => 'Empresa RJ',
            'state' => 'RJ',
            'city' => 'Rio de Janeiro',
            'tax_regime' => TaxRegime::PresumedProfit,
        ]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $sp->getKey(),
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-10-10',
        ]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients/summary?client_id='.$sp->getKey())->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.active', 1)
            ->assertJsonPath('data.certificate.expiring', 1);

        $this->getJson('/api/clients/summary?client_id='.$rj->getKey())->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.certificate.missing', 1);

        $this->getJson('/api/clients/analytics?client_id='.$sp->getKey())->assertOk()
            ->assertJsonPath('data.by_state.0.key', 'SP')
            ->assertJsonPath('data.by_state.0.count', 1)
            ->assertJsonPath('data.by_city.0.key', 'São Paulo')
            ->assertJsonPath('data.by_tax_regime.0.key', 'simple_national')
            ->assertJsonCount(1, 'data.attention.certificate');

        CarbonImmutable::setTestNow();
    }

    public function test_analytics_aggregates_geography_regime_and_attention(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        $account = Account::factory()->create();

        $sp = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => 'Alpha SP',
            'state' => 'SP',
            'city' => 'São Paulo',
            'tax_regime' => TaxRegime::SimpleNational,
            'legal_nature' => 'Sociedade Empresária Limitada',
            'primary_activity_code' => '6201-5/01',
            'primary_activity_description' => 'Desenvolvimento de software',
            'created_at' => '2026-01-15 10:00:00',
        ]);
        $ma = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => 'Beta MA',
            'state' => 'MA',
            'city' => 'São Luís',
            'tax_regime' => TaxRegime::Mei,
            'legal_nature' => 'Empresário Individual',
            'primary_activity_code' => '4711-3/02',
            'primary_activity_description' => 'Comércio varejista',
            'created_at' => '2026-03-10 10:00:00',
        ]);
        Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => 'Gamma SP',
            'state' => 'SP',
            'city' => 'Campinas',
            'tax_regime' => TaxRegime::SimpleNational,
            'created_at' => '2026-03-20 10:00:00',
        ]);

        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $sp->getKey(),
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-09-10',
        ]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $ma->getKey(),
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-10-10',
        ]);
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $sp->getKey(),
            'family' => '00006',
            'code' => '00006',
            'state' => SerproPowerOfAttorneyState::Established,
            'expires_on' => '2026-08-01',
        ]);

        $this->actingAs($this->memberOf($account), 'sanctum');

        $response = $this->getJson('/api/clients/analytics')->assertOk();

        $byState = collect($response->json('data.by_state'))->keyBy('key');
        $this->assertSame(2, $byState['SP']['count']);
        $this->assertSame(1, $byState['MA']['count']);

        $byRegion = collect($response->json('data.by_region'))->keyBy('key');
        $this->assertSame(2, $byRegion['Sudeste']['count']);
        $this->assertSame(1, $byRegion['Nordeste']['count']);

        $byRegime = collect($response->json('data.by_tax_regime'))->keyBy('key');
        $this->assertSame(2, $byRegime['simple_national']['count']);
        $this->assertSame(1, $byRegime['mei']['count']);

        $this->assertSame(1, collect($response->json('data.by_legal_nature'))->firstWhere('key', 'Sociedade Empresária Limitada')['count']);
        $this->assertSame(1, collect($response->json('data.by_activity'))->firstWhere('key', '6201-5/01')['count']);
        $this->assertSame('Desenvolvimento de software', collect($response->json('data.by_activity'))->firstWhere('key', '6201-5/01')['label']);

        $growth = collect($response->json('data.growth_by_month'))->keyBy('key');
        $this->assertSame(1, $growth['2026-01']['count']);
        $this->assertSame(2, $growth['2026-03']['count']);

        $certs = $response->json('data.attention.certificate');
        $this->assertCount(2, $certs);
        $this->assertEqualsCanonicalizing(
            ['expired', 'expiring'],
            array_column($certs, 'status')
        );

        $poas = $response->json('data.attention.poa');
        $this->assertCount(1, $poas);
        $this->assertSame('expired', $poas[0]['status']);
        $this->assertSame($sp->getKey(), $poas[0]['id']);

        CarbonImmutable::setTestNow();
    }

    public function test_client_id_must_belong_to_current_account(): void
    {
        $account = Account::factory()->create();
        $foreign = Client::factory()->individual()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients/summary?client_id='.$foreign->getKey())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->getJson('/api/clients/analytics?client_id='.$foreign->getKey())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
