<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\DeleteClientsJob;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\SerproClientAuthorization;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_portfolio_uses_the_default_page_size(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients')->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.from', null)
            ->assertJsonCount(0, 'data');
    }

    public function test_four_clients_fit_on_the_first_page(): void
    {
        $account = Account::factory()->create();
        Client::factory()->count(4)->create(['account_id' => $account->getKey()]);
        Client::factory()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients')->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 4)
            ->assertJsonCount(4, 'data');
    }

    public function test_page_size_boundaries_and_filters_apply_before_pagination(): void
    {
        $account = Account::factory()->create();
        Client::factory()->count(25)->create(['account_id' => $account->getKey(), 'name' => 'Visivel', 'status' => 'active']);
        Client::factory()->create(['account_id' => $account->getKey(), 'name' => 'Ultimo', 'status' => 'inactive']);
        Client::factory()->count(10)->create(['name' => 'Outro tenant']);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients?per_page=25')->assertOk()
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(25, 'data');

        $this->getJson('/api/clients?per_page=25&page=2')->assertOk()
            ->assertJsonPath('meta.from', 26)
            ->assertJsonPath('meta.to', 26)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/clients?per_page=100&status=active')->assertOk()
            ->assertJsonPath('meta.total', 25)
            ->assertJsonCount(25, 'data');
    }

    public function test_large_portfolios_stay_paged_inside_the_tenant(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $this->insertClients($account, 500);
        $this->insertClients($other, 40);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients?per_page=100&page=5')->assertOk()
            ->assertJsonPath('meta.total', 500)
            ->assertJsonPath('meta.last_page', 5)
            ->assertJsonPath('meta.from', 401)
            ->assertJsonPath('meta.to', 500)
            ->assertJsonCount(100, 'data');
    }

    public function test_option_filters_union_values_and_intersect_columns(): void
    {
        CarbonImmutable::setTestNow('2026-09-23 12:00:00');
        $account = Account::factory()->create();
        $meiExpired = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_regime' => 'mei', 'name' => 'MEI vencido']);
        $simpleExpiring = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_regime' => 'simple_national', 'name' => 'Simples a vencer']);
        $presumedExpired = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_regime' => 'presumed_profit', 'name' => 'Presumido vencido']);
        $meiValid = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_regime' => 'mei', 'name' => 'MEI válido']);
        ClientCertificate::factory()->create(['account_id' => $account->getKey(), 'client_id' => $meiExpired->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => '2026-09-01']);
        ClientCertificate::factory()->create(['account_id' => $account->getKey(), 'client_id' => $simpleExpiring->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => '2026-10-10']);
        ClientCertificate::factory()->create(['account_id' => $account->getKey(), 'client_id' => $presumedExpired->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => '2026-08-01']);
        ClientCertificate::factory()->create(['account_id' => $account->getKey(), 'client_id' => $meiValid->getKey(), 'valid_from' => '2026-01-01', 'valid_until' => '2027-06-01']);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $ids = fn (string $query): array => $this->getJson('/api/clients?'.$query)->assertOk()->json('data.*.id');

        $this->assertEqualsCanonicalizing(
            [$meiExpired->getKey(), $simpleExpiring->getKey(), $presumedExpired->getKey()],
            $ids('certificate_status[]=expired&certificate_status[]=expiring')
        );
        $this->assertEqualsCanonicalizing(
            [$meiExpired->getKey()],
            $ids('tax_regime[]=mei&tax_regime[]=simple_national&certificate_status[]=expired')
        );
        $this->assertEqualsCanonicalizing([$meiExpired->getKey(), $meiValid->getKey()], $ids('tax_regime=mei'));
        $this->getJson('/api/clients?tax_regime[]=nope')->assertUnprocessable()->assertJsonValidationErrors('tax_regime');

        CarbonImmutable::setTestNow();
    }

    public function test_sheet_lists_every_filtered_client_without_portfolio_details(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $alpha = Client::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Alpha',
            'status' => 'active',
            'email' => 'alpha@example.com',
            'city' => 'Recife',
        ]);
        ClientCertificate::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $alpha->getKey(),
            'subject' => 'Alpha Certificado',
            'valid_until' => now()->addYear(),
        ]);
        $tag = Tag::factory()->create(['account_id' => $account->getKey(), 'name' => 'Prioridade', 'color' => 'warning']);
        $alpha->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);
        Client::factory()->create(['account_id' => $account->getKey(), 'name' => 'Zulu', 'status' => 'inactive']);
        Client::factory()->create(['account_id' => $other->getKey(), 'name' => 'Alpha Alheio', 'status' => 'active']);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $response = $this->getJson('/api/clients?sheet=1&status=active&sort=name&direction=asc')->assertOk()
            ->assertJsonPath('meta.mode', 'sheet')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $alpha->getKey())
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.0.certificate_status', 'valid')
            ->assertJsonPath('data.0.tags.0.name', 'Prioridade')
            ->assertJsonPath('data.0.tags.0.color', 'warning')
            ->assertJsonPath('data.0.ecac_power_of_attorney_status', 'missing');

        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $alpha->getKey(),
            'family' => '00006',
            'code' => '00006',
            'expires_on' => now()->addYear()->toDateString(),
        ]);

        $this->getJson('/api/clients?sheet=1&status=active&sort=name&direction=asc')->assertOk()
            ->assertJsonPath('data.0.ecac_power_of_attorney_status', 'valid')
            ->assertJsonPath('data.0.ecac_power_of_attorney.status', 'valid')
            ->assertJsonPath('data.0.ecac_power_of_attorney.families.0.family', '00006');

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('address', $row);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('current_page', $response->json('meta'));
        $this->assertArrayNotHasKey('subject', $row['certificate']);
        $this->assertArrayHasKey('valid_until', $row['certificate']);
    }

    public function test_sheet_above_the_limit_pages_inside_the_tenant_without_shrinking_selection(): void
    {
        config(['clients.sheet_limit' => 2, 'clients.sheet_page_size' => 2]);
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $this->insertClients($account, 5, 'active');
        $foreign = Client::factory()->create(['account_id' => $other->getKey(), 'name' => 'Alheio', 'status' => 'active']);
        $firstId = Client::query()->where('account_id', $account->getKey())->orderBy('name')->limit(1)->value('id');
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $firstId,
            'family' => '00006',
            'code' => '00006',
            'expires_on' => now()->addYear()->toDateString(),
        ]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients?sheet=1&status=active&sort=name')->assertOk()
            ->assertJsonPath('meta.mode', 'paged')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.id', $firstId)
            ->assertJsonPath('data.0.ecac_power_of_attorney_status', 'valid')
            ->assertJsonPath('data.1.ecac_power_of_attorney_status', 'missing')
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/clients?sheet=1&status=active&sort=name&page=3')->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonCount(1, 'data');

        $created = $this->postJson('/api/clients/selections', ['status' => 'active'])->assertOk()
            ->assertJsonPath('data.count', 5)
            ->assertJsonCount(5, 'data.ids');

        $this->assertNotContains($foreign->getKey(), $created->json('data.ids'));
    }

    public function test_sheet_of_two_thousand_returns_the_whole_filtered_tenant(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $this->insertClients($account, 2000, 'active');
        $this->insertClients($account, 10, 'inactive');
        $this->insertClients($other, 25, 'active');
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/clients?sheet=1&status=active&sort=name')->assertOk()
            ->assertJsonPath('meta.mode', 'sheet')
            ->assertJsonPath('meta.total', 2000)
            ->assertJsonCount(2000, 'data');
    }

    public function test_global_selection_snapshots_the_filtered_tenant_and_ignores_later_rows(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $this->insertClients($account, 2000, 'active');
        $this->insertClients($account, 15, 'inactive');
        $foreign = Client::factory()->create(['account_id' => $other->getKey(), 'name' => 'Alheio']);
        $user = $this->memberOf($account);
        $this->actingAs($user, 'sanctum');

        $created = $this->postJson('/api/clients/selections', ['status' => 'active'])->assertOk()
            ->assertJsonPath('data.count', 2000)
            ->assertJsonCount(2000, 'data.ids');
        $selection = $created->json('data.id');
        $this->assertNotContains($foreign->getKey(), $created->json('data.ids'));

        $late = Client::factory()->create(['account_id' => $account->getKey(), 'status' => 'active', 'name' => 'Depois']);
        $kept = Client::query()->where('account_id', $account->getKey())->where('status', 'active')->whereKeyNot($late->getKey())->orderBy('id')->limit(3)->pluck('id')->all();

        $this->postJson("/api/clients/selections/{$selection}/presence", ['ids' => [...$kept, $late->getKey(), $foreign->getKey()]])
            ->assertOk()
            ->assertJsonPath('data.ids', $kept);

        $this->assertNotSoftDeleted('clients', ['id' => $late->getKey()]);
    }

    public function test_queued_global_delete_keeps_exclusions_and_foreign_ids(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $this->insertClients($account, 120);
        $foreign = Client::factory()->create(['account_id' => $other->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $selection = $this->postJson('/api/clients/selections')->assertOk()->json('data.id');
        $kept = Client::query()->orderBy('id')->limit(2)->pluck('id')->all();

        Queue::fake();

        $this->postJson('/api/clients/bulk-deletions', [
            'selection_id' => $selection,
            'excluded_ids' => [$kept[0]],
            'ids' => [$foreign->getKey()],
        ])->assertAccepted()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.total', 120);

        Queue::assertPushed(DeleteClientsJob::class, function (DeleteClientsJob $job): bool {
            app()->call([$job, 'handle']);

            return true;
        });

        $this->assertNotSoftDeleted('clients', ['id' => $kept[0]]);
        $this->assertSoftDeleted('clients', ['id' => $kept[1]]);
        $this->assertNotSoftDeleted('clients', ['id' => $foreign->getKey()]);
        $this->assertSame(1, Client::withoutGlobalScopes()->where('account_id', $account->getKey())->whereNull('deleted_at')->count());
    }

    public function test_explicit_delete_skips_other_tenants_and_page_selection_does_not_span_unselected_rows(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $mine = Client::factory()->count(3)->create(['account_id' => $account->getKey()]);
        $foreign = Client::factory()->create(['account_id' => $other->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->postJson('/api/clients/bulk-deletions', [
            'ids' => [$mine[0]->getKey(), $mine[1]->getKey(), $foreign->getKey()],
        ])->assertOk()
            ->assertJsonPath('data.deleted', 2)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSoftDeleted('clients', ['id' => $mine[0]->getKey()]);
        $this->assertNotSoftDeleted('clients', ['id' => $mine[2]->getKey()]);
        $this->assertNotSoftDeleted('clients', ['id' => $foreign->getKey()]);
    }

    public function test_reader_cannot_select_or_bulk_delete(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account, 'user'), 'sanctum');

        $this->postJson('/api/clients/selections')->assertForbidden();
        $this->postJson('/api/clients/bulk-deletions', ['ids' => [$client->getKey()]])->assertForbidden();
        $this->assertNotSoftDeleted('clients', ['id' => $client->getKey()]);
    }

    public function test_selection_from_another_user_or_tenant_cannot_be_reused(): void
    {
        $account = Account::factory()->create();
        Client::factory()->create(['account_id' => $account->getKey()]);
        $owner = $this->memberOf($account);
        $selection = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/clients/selections')
            ->assertOk()
            ->json('data.id');

        $this->actingAs($this->memberOf($account), 'sanctum')
            ->postJson('/api/clients/bulk-deletions', ['selection_id' => $selection])
            ->assertNotFound();

        $this->actingAs($this->memberOf(Account::factory()->create()), 'sanctum')
            ->postJson('/api/clients/bulk-deletions', ['selection_id' => $selection])
            ->assertNotFound();
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    private function insertClients(Account $account, int $count, string $status = 'active'): void
    {
        $now = now();
        $pending = [];

        for ($index = 0; $index < $count; $index++) {
            $pending[] = [
                'account_id' => $account->getKey(),
                'name' => 'Empresa '.$account->getKey().'-'.$index,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($pending) === 500) {
                DB::table('clients')->insert($pending);
                $pending = [];
            }
        }

        if ($pending !== []) {
            DB::table('clients')->insert($pending);
        }
    }
}
