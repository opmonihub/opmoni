<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\ClientEcacPowerOfAttorney;
use App\Models\ClientSavedFilter;
use App\Models\Department;
use App\Models\Plan;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use App\Models\SerproMonitoring;
use App\Models\Subscription;
use App\Models\SupportAccessLog;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TemplateClientException;
use App\Models\User;
use App\Services\PlanLimits;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SecurityRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_calendar_requires_from_and_to(): void
    {
        $member = $this->memberOf(Account::factory()->create(), 'operador');

        $this->actingAs($member, 'sanctum')->getJson('/api/work/calendar')->assertStatus(422);
    }

    public function test_tenant_models_do_not_mass_assign_account_id_but_inherit_it(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $attempt = new Client(['account_id' => 999, 'name' => 'Tentativa']);

        $this->assertArrayNotHasKey('account_id', $attempt->getAttributes());

        $created = Client::query()->create(['name' => 'Herdado']);

        $this->assertSame($account->getKey(), $created->account_id);
        $this->assertSame($account->getKey(), $created->refresh()->account_id);
    }

    public function test_all_tenant_models_exclude_account_id_from_fillable(): void
    {
        $guarded = [
            Client::class,
            ClientCertificate::class,
            ClientEcacPowerOfAttorney::class,
            ProcessTemplate::class,
            ProcessTemplateTask::class,
            Tag::class,
            Department::class,
            Process::class,
            Task::class,
            SerproMonitoring::class,
            ClientSavedFilter::class,
            TemplateClientException::class,
            SupportAccessLog::class,
            Subscription::class,
        ];

        foreach ($guarded as $class) {
            $this->assertNotContains(
                'account_id',
                (new $class)->getFillable(),
                "{$class} must not list account_id as fillable"
            );
        }

        $this->assertContains('account_id', (new AccountUser)->getFillable());
    }

    public function test_sensitive_routes_carry_throttle_middleware(): void
    {
        $this->assertContains('throttle:5,1', $this->routeMiddleware('POST', 'api/register'));
        $this->assertContains('throttle:10,1', $this->routeMiddleware('POST', 'api/login'));
        $this->assertContains('throttle:10,1', $this->routeMiddleware('POST', 'api/clients/cnpj-lookup'));
    }

    public function test_register_is_throttled_after_five_attempts(): void
    {
        $payload = [
            'name' => 'Chef',
            'email' => 'chef@opmoni.dev',
            'password' => 'password123',
            'company' => 'HQ',
            'size' => '1-10',
        ];

        $statuses = [];

        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->postJson('/api/register', $payload)->status();
        }

        $this->assertSame(201, $statuses[0]);
        $this->assertSame([403, 403, 403, 403], array_slice($statuses, 1, 4));
        $this->assertSame(403, $statuses[5]);
        $this->assertSame(429, $statuses[6]);
    }

    public function test_plan_limits_are_null_safe_and_cast_to_int(): void
    {
        $orphan = Account::withoutEvents(fn (): Account => Account::factory()->create());

        $this->assertNull($orphan->subscription);

        PlanLimits::assertCanCreate($orphan, 'clients');

        $account = Account::factory()->create();
        $plan = Plan::bySlug('basico');
        $plan->forceFill(['limits' => ['users' => 5, 'clients' => '1', 'monitorings' => 100]])->save();

        Client::factory()->create(['account_id' => $account->getKey()]);

        try {
            PlanLimits::assertCanCreate($account->refresh(), 'clients');

            $this->fail('Expected ValidationException when the string limit is reached.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('limit', $exception->errors());
        }
    }

    public function test_account_role_is_memoized_per_account(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'operador');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('operador', $member->accountRole($account));
        $this->assertSame('operador', $member->accountRole($account->getKey()));

        $roleQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'account_user'))
            ->count();

        $this->assertSame(1, $roleQueries);

        $other = Account::factory()->create();

        $this->assertNull($member->accountRole($other));

        $roleQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'account_user'))
            ->count();

        $this->assertSame(2, $roleQueries);
    }

    /**
     * @return list<string>
     */
    private function routeMiddleware(string $method, string $uri): array
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => in_array($method, $route->methods(), true) && $route->uri() === $uri
        );

        $this->assertNotNull($route, "Route {$method} {$uri} not found.");

        return $route->gatherMiddleware();
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
