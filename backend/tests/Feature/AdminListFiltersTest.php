<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class AdminListFiltersTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_accounts_are_searched_and_filtered_before_pagination(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create([
            'name' => 'Conta Agulha Fiscal',
            'status' => 'suspended',
        ]);
        Account::factory()->create(['name' => 'Conta Agulha Ativa', 'status' => 'active']);
        Account::factory()->count(16)->create();

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/accounts?q=agulha&status=suspended')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $target->getKey());
    }

    public function test_subscriptions_are_searched_by_related_data_and_filtered_before_pagination(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create(['name' => 'Conta Assinatura Alvo']);
        $target->subscription()->update([
            'plan_id' => Plan::query()->where('slug', 'profissional')->valueOrFail('id'),
            'status' => 'past_due',
        ]);
        $other = Account::factory()->create(['name' => 'Outra Assinatura']);
        $other->subscription()->update([
            'plan_id' => Plan::query()->where('slug', 'profissional')->valueOrFail('id'),
            'status' => 'active',
        ]);
        Account::factory()->count(16)->create();

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/subscriptions?q=profissional&status=past_due')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.account.id', $target->getKey());
    }

    public function test_users_are_searched_and_filtered_before_pagination(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = User::factory()->create([
            'name' => 'Pessoa Agulha',
            'email' => 'agulha@example.test',
        ]);
        $other = User::factory()->create([
            'name' => 'Admin Agulha',
            'email' => 'admin.agulha@example.test',
        ]);
        $other->forceFill(['is_super_admin' => true])->save();
        User::factory()->count(16)->create();

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/users?q=agulha%40example.test&type=user')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $target->getKey());
    }

    public function test_admin_list_filters_reject_unsupported_values(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/accounts?status=deleted')
            ->assertUnprocessable();

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/subscriptions?status=trial')
            ->assertUnprocessable();

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/users?type=operator')
            ->assertUnprocessable();
    }

    public function test_admin_search_treats_like_wildcards_as_literal_characters(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        Account::factory()->create(['name' => 'Conta Fiscal']);
        User::factory()->create(['name' => 'Pessoa Fiscal', 'email' => 'fiscal@example.test']);
        $accountWithUnderscore = Account::factory()->create(['name' => 'Conta_Literal']);
        $userWithUnderscore = User::factory()->create(['name' => 'Pessoa Literal', 'email' => 'literal_name@example.test']);

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/accounts?q=%25')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/subscriptions?q=%25')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/users?q=%25')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/accounts?q=_')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $accountWithUnderscore->getKey());

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/subscriptions?q=_')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.account.id', $accountWithUnderscore->getKey());

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/v1/admin/users?q=_')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $userWithUnderscore->getKey());
    }

    public function test_a_plan_can_clear_all_limits_and_rename_while_unlimited(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $plan = Plan::bySlug('basico');

        $this->actingAs($superAdmin, 'web')
            ->putJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Unlimited', 'limits' => (object) []])
            ->assertOk()
            ->assertJsonPath('name', 'Unlimited');

        $this->assertSame(['users' => null, 'clients' => null, 'monitorings' => null], $plan->refresh()->limits);

        $this->putJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Unlimited renamed', 'limits' => (object) []])
            ->assertOk();

        $this->assertSame('Unlimited renamed', $plan->refresh()->name);
        $this->assertSame(['users' => null, 'clients' => null, 'monitorings' => null], $plan->limits);
    }

    public function test_omitting_plan_limits_preserves_existing_limits(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $plan = Plan::bySlug('basico');
        $before = $plan->limits;

        $this->actingAs($superAdmin, 'web')
            ->putJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame($before, $plan->refresh()->limits);
        $this->putJson('/api/v1/admin/plans/'.$plan->id, ['limits' => null])->assertUnprocessable();
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

        $this->selectBusinessAccount($superAdmin, $home->getKey());

        return $superAdmin->refresh();
    }
}
