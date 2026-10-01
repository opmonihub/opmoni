<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_operador_and_user_list_directory_without_email(): void
    {
        $account = Account::factory()->create();
        $this->memberOf($account, 'operador', ['name' => 'Beto', 'email' => 'beto@opmoni.dev']);
        $this->memberOf($account, 'user', ['name' => 'Ana', 'email' => 'ana@opmoni.dev']);

        foreach (['operador', 'user'] as $role) {
            $member = User::firstWhere('email', $role === 'operador' ? 'beto@opmoni.dev' : 'ana@opmoni.dev');
            $response = $this->actingAs($member, 'sanctum')->getJson('/api/account/members/directory');
            $response->assertOk();
            $response->assertJsonPath('data.0.name', 'Ana');
            $response->assertJsonMissing(['email' => 'ana@opmoni.dev']);
        }
    }

    public function test_user_reads_directory_shape_used_by_work_assignment(): void
    {
        $account = Account::factory()->create();
        $this->memberOf($account, 'operador', ['name' => 'Beto', 'email' => 'beto@opmoni.dev']);
        $user = $this->memberOf($account, 'user', ['name' => 'Ana', 'email' => 'ana@opmoni.dev']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/account/members/directory');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'name', 'role', 'departments']]]);
        $response->assertJsonPath('data.0.name', 'Ana');
        $response->assertJsonMissing(['email' => 'ana@opmoni.dev']);
        $response->assertJsonMissing(['email' => 'beto@opmoni.dev']);
    }

    public function test_directory_returns_linked_departments_scoped_to_account(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $ana = $this->memberOf($account, 'user', ['name' => 'Ana']);
        $beto = $this->memberOf($account, 'operador', ['name' => 'Beto']);

        AccountUser::create(['account_id' => $other->getKey(), 'user_id' => $ana->getKey(), 'role' => 'admin']);

        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $pessoal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Pessoal')
            ->sole();
        $fiscal->members()->attach($ana->getKey(), ['account_id' => $account->getKey()]);
        $pessoal->members()->attach($ana->getKey(), ['account_id' => $account->getKey()]);

        $foreign = Department::factory()->create(['account_id' => $other->getKey(), 'name' => 'Estrangeiro', 'color' => 'warning']);
        $foreign->members()->attach($ana->getKey(), ['account_id' => $other->getKey()]);

        $response = $this->actingAs($beto, 'sanctum')->getJson('/api/account/members/directory');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonCount(2, 'data.0.departments');
        $response->assertJsonCount(0, 'data.1.departments');
        $response->assertJson([
            'data' => [
                [
                    'id' => $ana->getKey(),
                    'name' => 'Ana',
                    'role' => 'user',
                    'departments' => [
                        ['id' => $fiscal->getKey(), 'name' => 'Fiscal', 'color' => 'success'],
                        ['id' => $pessoal->getKey(), 'name' => 'Pessoal', 'color' => 'info'],
                    ],
                ],
                [
                    'id' => $beto->getKey(),
                    'name' => 'Beto',
                    'role' => 'operador',
                    'departments' => [],
                ],
            ],
        ]);
        $response->assertJsonMissing(['name' => 'Estrangeiro']);
    }

    public function test_directory_never_leaks_other_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $this->memberOf($accountB, 'admin', ['name' => 'Forasteiro']);
        $member = $this->memberOf($accountA, 'operador');

        $response = $this->actingAs($member, 'sanctum')->getJson('/api/account/members/directory');

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'Forasteiro']);
    }

    public function test_admin_lists_directory_without_email(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin', ['name' => 'Chefe', 'email' => 'chefe@opmoni.dev']);
        $this->memberOf($account, 'user', ['name' => 'Ana', 'email' => 'ana@opmoni.dev']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/account/members/directory');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'name', 'role', 'departments']]]);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Ana');
        $response->assertJsonPath('data.1.name', 'Chefe');
        $response->assertJsonMissing(['email' => 'ana@opmoni.dev']);
        $response->assertJsonMissing(['email' => 'chefe@opmoni.dev']);
    }

    public function test_operador_still_cannot_invite_members(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'sanctum')->postJson('/api/account/members', [
            'name' => 'Convidado', 'email' => 'convidado@opmoni.dev',
            'password' => 'password123', 'role' => 'user',
        ])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function memberOf(Account $account, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
