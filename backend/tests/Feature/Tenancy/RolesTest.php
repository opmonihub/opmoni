<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\AccessSecurity;
use App\Http\Middleware\ResolveAccount;
use App\Http\Middleware\SerializedAccessSession;
use App\Http\Middleware\TrustedIngress;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use App\Services\PlanLimits;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\BusinessTestCase;

class RolesTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        Route::middleware([TrustedIngress::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, SerializedAccessSession::class, AccessSecurity::class, 'auth', ResolveAccount::class, SubstituteBindings::class])->group(function (): void {
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
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'web')
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
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');

        $this->actingAs($admin, 'web')
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
        $this->assertSame($account->getKey(), $this->app['session.store']->get('current_account_id'));
        $this->assertSame($admin->getKey(), AccountUser::firstWhere([
            'account_id' => $account->getKey(),
            'user_id' => $member->getKey(),
        ])->inviter_id);
    }

    public function test_admin_can_remove_member(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');
        $member = $this->memberOf($account, 'user');

        $this->actingAs($admin, 'web')
            ->deleteJson("/_test/account/members/{$member->getKey()}")
            ->assertNoContent();

        $this->assertNull($member->accountRole($account));
    }

    public function test_user_role_can_read_but_not_create_clients(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $user = $this->memberOf($account, 'user');

        Client::factory()->create(['account_id' => $account->getKey()]);

        $this->actingAs($user, 'web')
            ->getJson('/_test/clients')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($user, 'web')
            ->postJson('/_test/clients', ['name' => 'Cliente X'])
            ->assertForbidden();
    }

    public function test_operador_can_create_client(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');

        $this->actingAs($operador, 'web')
            ->postJson('/_test/clients', ['name' => 'Cliente X'])
            ->assertCreated();

        $this->assertSame($account->getKey(), Client::withoutGlobalScopes()->sole()->account_id);
    }

    public function test_invite_beyond_user_limit_returns_422(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');

        foreach (range(1, 4) as $i) {
            $this->memberOf($account, 'user', ['email' => "extra{$i}@opmoni.dev"]);
        }

        $this->assertSame(5, $account->members()->count());

        $this->actingAs($admin, 'web')
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
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/admin/accounts')
            ->assertForbidden();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/admin/plans')
            ->assertForbidden();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/admin/support/logs')
            ->assertForbidden();

        $this->actingAs($admin, 'web')
            ->postJson("/api/v1/support/accounts/{$account->getKey()}/enter")
            ->assertNotFound();
    }

    public function test_only_super_admin_can_create_accounts(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');

        $before = Account::count();

        $this->actingAs($admin, 'web')
            ->postJson('/api/v1/admin/accounts', ['name' => 'Filial Proibida'])
            ->assertForbidden();

        $this->assertSame($before, Account::count());

        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($superAdmin, 'web')
            ->postJson('/api/v1/admin/accounts', [
                'name' => 'Filial Nova',
                'owner_name' => 'New Owner',
                'login_email' => 'new-owner@example.test',
            ])
            ->assertCreated();

        $created = Account::firstWhere('name', 'Filial Nova');
        $this->assertNotNull($created);
        $this->assertSame(1, $created->subscription()->count());
        $this->assertSame('active', $created->subscription->status);
        $this->assertDatabaseHas('owner_activations', ['account_id' => $created->id, 'email' => 'new-owner@example.test', 'owner_name' => 'New Owner']);
        $this->assertDatabaseMissing('users', ['email' => 'new-owner@example.test']);
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

        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
