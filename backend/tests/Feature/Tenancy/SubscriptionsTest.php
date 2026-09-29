<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Plan;
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

class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        Route::middleware(['auth:sanctum', 'tenant', SubstituteBindings::class])->group(function (): void {
            Route::get('/_test/clients', fn () => Client::all());

            Route::post('/_test/clients', function (Request $request) {
                Gate::authorize('create', Client::class);

                $account = Account::findOrFail(resolve(CurrentTenant::class)->accountId);
                PlanLimits::assertCanCreate($account, 'clients');

                $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

                return response()->json(Client::create($data), 201);
            });
        });
    }

    public function test_new_account_has_single_active_basico_subscription(): void
    {
        $account = Account::factory()->create();

        $this->assertSame(1, $account->subscription()->count());
        $this->assertSame('active', $account->subscription->status);
        $this->assertSame(Plan::bySlug('basico')->getKey(), $account->subscription->plan_id);
        $this->assertSame(
            ['users' => 5, 'clients' => 50],
            $account->subscription->plan->limits
        );
    }

    public function test_51st_client_on_basico_returns_422_and_count_stays_50(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');

        Client::factory()->count(50)->create(['account_id' => $account->getKey()]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/_test/clients', ['name' => 'Cliente 51'])
            ->assertUnprocessable();

        $this->assertSame(50, $account->clients()->count());
    }

    public function test_unlimited_plan_allows_beyond_basico_cap(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');

        $account->subscription->update(['plan_id' => Plan::bySlug('empresarial')->getKey()]);

        Client::factory()->count(50)->create(['account_id' => $account->getKey()]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/_test/clients', ['name' => 'Cliente 51'])
            ->assertCreated();

        $this->assertSame(51, $account->clients()->count());
    }

    public function test_write_blocked_when_subscription_past_due(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');

        $account->subscription->update(['status' => 'past_due']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/_test/clients', ['name' => 'Cliente X'])
            ->assertForbidden();

        $this->assertSame(0, $account->clients()->count());

        $this->actingAs($admin, 'sanctum')
            ->getJson('/_test/clients')
            ->assertOk();
    }

    public function test_chave_de_limite_desconhecida_nao_tem_contagem(): void
    {
        $account = Account::factory()->create();

        // Não existe mais contagem de monitoramentos por plano: o que o limite
        // cobria virou "cliente × obrigação", e uma chave que ninguém conta é
        // ilimitada — nunca uma proibição silenciosa.
        PlanLimits::assertCanCreate($account, 'monitorings');

        $this->assertSame(0, $account->monitorings()->count());
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();

        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
