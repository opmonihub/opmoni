<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        config()->set('sanctum.stateful', ['localhost:3000']);
    }

    public function test_first_registered_user_becomes_super_admin(): void
    {
        $response = $this->postJson('/api/register', $this->registerPayload());
        $response->assertCreated();

        $user = User::firstWhere('email', 'chef@opmoni.dev');
        $this->assertTrue($user->isSuperAdmin());

        $account = Account::firstWhere('name', 'HQ');
        $this->assertNotNull($account);
        $this->assertSame($account->getKey(), $user->current_account_id);
        $this->assertSame('admin', $user->accountRole($account));
        $this->assertSame(Plan::bySlug('basico')->getKey(), $account->subscription->plan_id);
    }

    public function test_login_with_valid_credentials_authenticates_subsequent_requests(): void
    {
        $user = User::factory()->create(['email' => 'chef@opmoni.dev']);

        $login = $this->spaPostJson('/api/login', [
            'email' => 'chef@opmoni.dev',
            'password' => 'password',
        ]);

        $login->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->spaGetJson('/api/me')
            ->assertOk()
            ->assertJson(['email' => 'chef@opmoni.dev']);
    }

    public function test_login_with_invalid_credentials_returns_422_without_session(): void
    {
        User::factory()->create(['email' => 'chef@opmoni.dev']);

        $login = $this->spaPostJson('/api/login', [
            'email' => 'chef@opmoni.dev',
            'password' => 'wrong-password',
        ]);

        $login->assertUnprocessable();
        $this->assertGuest();

        $this->spaGetJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_destroys_session(): void
    {
        User::factory()->create(['email' => 'chef@opmoni.dev']);

        $this->spaPostJson('/api/login', [
            'email' => 'chef@opmoni.dev',
            'password' => 'password',
        ])->assertOk();

        $this->spaPostJson('/api/logout', [])->assertNoContent();

        // auth:sanctum switches the default guard to sanctum via shouldUse,
        // so assert against the session guard, the source of truth for logout.
        $this->assertGuest('web');

        $this->spaGetJson('/api/me')->assertUnauthorized();
    }

    public function test_me_without_session_returns_401(): void
    {
        $this->spaGetJson('/api/me')->assertUnauthorized();
    }

    public function test_registration_status_allows_initial_onboarding_without_users_or_accounts(): void
    {
        $this->getJson('/api/registration-status')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('registration_available', true);
    }

    public function test_registration_status_blocks_onboarding_when_a_user_exists(): void
    {
        User::factory()->create();

        $this->getJson('/api/registration-status')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('registration_available', false);
    }

    public function test_registration_status_blocks_onboarding_when_an_account_exists(): void
    {
        Account::create(['name' => 'Existente']);

        $this->getJson('/api/registration-status')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('registration_available', false);
    }

    public function test_me_with_session_returns_profile_accounts_and_current_account(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $this->spaPostJson('/api/login', [
            'email' => 'chef@opmoni.dev',
            'password' => 'password123',
        ])->assertOk();

        $this->spaGetJson('/api/me')
            ->assertOk()
            ->assertJson([
                'email' => 'chef@opmoni.dev',
                'is_super_admin' => true,
            ])
            ->assertJsonStructure([
                'accounts' => [['id', 'name', 'role', 'is_member']],
                'current_account' => ['id', 'name'],
            ])
            ->assertJsonPath('accounts.0.name', 'HQ')
            ->assertJsonPath('accounts.0.role', 'admin')
            ->assertJsonPath('accounts.0.is_member', true)
            ->assertJsonPath('current_account.name', 'HQ');
    }

    public function test_register_with_existing_user_returns_403_and_creates_nothing(): void
    {
        User::factory()->create();

        $response = $this->spaPostJson('/api/register', $this->registerPayload());

        $response->assertForbidden();
        $this->assertSame(1, User::count());
        $this->assertSame(0, Account::count());
    }

    public function test_register_with_existing_account_returns_403_and_creates_nothing(): void
    {
        Account::create(['name' => 'Existente']);

        $response = $this->spaPostJson('/api/register', $this->registerPayload());

        $response->assertForbidden();
        $this->assertSame(0, User::count());
        $this->assertSame(1, Account::count());
    }

    /**
     * @return array{name: string, email: string, password: string, company: string, size: string}
     */
    private function registerPayload(): array
    {
        return [
            'name' => 'Chefona',
            'email' => 'chef@opmoni.dev',
            'password' => 'password123',
            'company' => 'HQ',
            'size' => 'Só eu',
        ];
    }

    /**
     * A sessão do SPA só é segura com o middleware do Sanctum: é ele que
     * restringe o cookie às origens de `sanctum.stateful` e valida o CSRF.
     * Anexar só `StartSession` ao grupo `api` autentica por cookie sem essas
     * duas guardas. O `ValidateCsrfToken` pula a checagem em teste unitário,
     * então um 419 não provaria nada; a presença no grupo é o que se confere.
     */
    public function test_o_grupo_api_carrega_o_middleware_stateful_do_sanctum(): void
    {
        $groups = app(Kernel::class)->getMiddlewareGroups();

        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            $groups['api'],
        );
    }

    /**
     * @return array{Referer: string, Origin: string}
     */
    private function spaHeaders(): array
    {
        return [
            'Referer' => 'http://localhost:3000',
            'Origin' => 'http://localhost:3000',
        ];
    }

    private function forwardCookies(TestResponse $response): void
    {
        foreach (['XSRF-TOKEN', config('session.cookie')] as $name) {
            $cookie = $response->getCookie($name, false);

            if ($cookie instanceof Cookie) {
                $this->withUnencryptedCookie($name, $cookie->getValue());
            }
        }
    }

    private function primeSpaSession(): string
    {
        // Fresh guards per request: mirrors production (new container per
        // HTTP request) instead of reusing guards cached from prior requests.
        Auth::forgetGuards();

        $response = $this->withCredentials()->withHeaders($this->spaHeaders())->get('/sanctum/csrf-cookie');
        $response->assertNoContent();
        $this->forwardCookies($response);

        /** @var string */
        return $response->getCookie('XSRF-TOKEN')->getValue();
    }

    private function spaPostJson(string $uri, array $data): TestResponse
    {
        Auth::forgetGuards();

        $response = $this->withCredentials()
            ->withHeaders($this->spaHeaders())
            ->withHeader('X-XSRF-TOKEN', $this->primeSpaSession())
            ->postJson($uri, $data);

        $this->forwardCookies($response);

        return $response;
    }

    private function spaGetJson(string $uri): TestResponse
    {
        Auth::forgetGuards();

        return $this->withCredentials()->withHeaders($this->spaHeaders())->getJson($uri);
    }
}
