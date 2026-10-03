<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use App\Services\PlanLimits;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        Route::middleware(['auth:sanctum', 'tenant', SubstituteBindings::class])->group(function (): void {
            Route::get('/_test/clients', function () {
                Gate::authorize('viewAny', Client::class);

                return Client::all();
            });

            Route::post('/_test/clients', function (Request $request) {
                Gate::authorize('create', Client::class);

                $account = Account::findOrFail(resolve(CurrentTenant::class)->accountId);
                PlanLimits::assertCanCreate($account, 'clients');

                $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

                return response()->json(Client::create($data), 201);
            });

            Route::post('/_test/account/members', function (Request $request) {
                $account = Account::findOrFail(resolve(CurrentTenant::class)->accountId);
                Gate::authorize('manageMembers', $account);
                PlanLimits::assertCanCreate($account, 'users');

                $data = $request->validate([
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'unique:users'],
                    'password' => ['required', 'string', 'min:8'],
                    'role' => ['required', 'in:admin,operador,user'],
                ]);

                $member = new User;
                $member->name = $data['name'];
                $member->email = $data['email'];
                $member->password = $data['password'];
                $member->current_account_id = $account->getKey();
                $member->save();

                $account->members()->attach($member, [
                    'role' => $data['role'],
                    'inviter_id' => $request->user()->getKey(),
                ]);

                return response()->json($member, 201);
            });

            Route::delete('/_test/account/members/{user}', function (User $user) {
                $account = Account::findOrFail(resolve(CurrentTenant::class)->accountId);
                Gate::authorize('manageMembers', $account);

                if ($user->accountRole($account) === null) {
                    abort(404);
                }

                $account->members()->detach($user);

                return response()->noContent();
            });
        });
    }

    public function test_operador_cannot_manage_members(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'sanctum')
            ->postJson('/_test/account/members', [
                'name' => 'Convidado',
                'email' => 'convidado@opmoni.dev',
                'password' => 'password123',
                'role' => 'user',
            ])
            ->assertForbidden();

        $this->assertNull(User::firstWhere('email', 'convidado@opmoni.dev'));
    }

    public function test_admin_can_invite_member(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/_test/account/members', [
                'name' => 'Convidado',
                'email' => 'convidado@opmoni.dev',
                'password' => 'password123',
                'role' => 'operador',
            ])
            ->assertCreated();

        $member = User::firstWhere('email', 'convidado@opmoni.dev');
        $this->assertNotNull($member);
        $this->assertSame('operador', $member->accountRole($account));
        $this->assertSame($account->getKey(), $member->current_account_id);
        $this->assertSame($admin->getKey(), AccountUser::firstWhere([
            'account_id' => $account->getKey(),
            'user_id' => $member->getKey(),
        ])->inviter_id);
    }

    public function test_admin_can_remove_member(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $member = $this->memberOf($account, 'user');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/_test/account/members/{$member->getKey()}")
            ->assertNoContent();

        $this->assertNull($member->accountRole($account));
    }

    public function test_user_role_can_read_but_not_create_clients(): void
    {
        $account = Account::factory()->create();
        $user = $this->memberOf($account, 'user');

        Client::factory()->create(['account_id' => $account->getKey()]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/_test/clients')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/_test/clients', ['name' => 'Cliente X'])
            ->assertForbidden();
    }

    public function test_operador_can_create_client(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'sanctum')
            ->postJson('/_test/clients', ['name' => 'Cliente X'])
            ->assertCreated();

        $this->assertSame($account->getKey(), Client::withoutGlobalScopes()->sole()->account_id);
    }

    public function test_invite_beyond_user_limit_returns_422(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');

        foreach (range(1, 4) as $i) {
            $this->memberOf($account, 'user', ['email' => "extra{$i}@opmoni.dev"]);
        }

        $this->assertSame(5, $account->members()->count());

        $this->actingAs($admin, 'sanctum')
            ->postJson('/_test/account/members', [
                'name' => 'Sexto',
                'email' => 'sexto@opmoni.dev',
                'password' => 'password123',
                'role' => 'user',
            ])
            ->assertUnprocessable();

        $this->assertSame(5, $account->members()->count());
        $this->assertNull(User::firstWhere('email', 'sexto@opmoni.dev'));
    }

    public function test_account_admin_cannot_access_admin_or_support_routes(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/accounts')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/plans')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support/logs')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/support/accounts/{$account->getKey()}/enter")
            ->assertForbidden();
    }

    public function test_only_super_admin_can_create_accounts(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');

        $before = Account::count();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/accounts', ['name' => 'Filial Proibida'])
            ->assertForbidden();

        $this->assertSame($before, Account::count());

        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/admin/accounts', [
                'name' => 'Filial Nova',
                'phone' => '11999998888',
                'phone_whatsapp' => true,
                'state' => 'sp',
                'city' => 'São Paulo',
            ])
            ->assertCreated();

        $created = Account::firstWhere('name', 'Filial Nova');
        $this->assertNotNull($created);
        $this->assertSame(1, $created->subscription()->count());
        $this->assertSame('active', $created->subscription->status);
        $this->assertSame([
            'phone' => '11999998888',
            'phone_whatsapp' => true,
            'state' => 'SP',
            'city' => 'São Paulo',
        ], $created->settings['platform']['billing_contact']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function memberOf(Account $account, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
