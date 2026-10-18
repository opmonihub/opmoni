<?php

namespace Tests\Feature;

use App\Actions\AccessAccounts;
use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\OwnerActivation;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.registration_enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
        $this->seed(PlanSeeder::class);
        User::factory()->create();
        Account::factory()->create();
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    public function test_registration_status_respects_gate_and_platform(): void
    {
        config(['erp.registration_enabled' => false]);
        $this->getJson('/api/v1/erp/registration-status')
            ->assertOk()
            ->assertJsonPath('platform_ready', true)
            ->assertJsonPath('registration_available', false);
    }

    public function test_new_owner_registration_creates_pending_subscription_without_office_account(): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/register', [
                'name' => 'ERP Owner',
                'email' => 'erp-new@example.test',
                'password' => 'password123',
                'company_name' => 'Nova Empresa',
                'cnpj' => self::VALID_CNPJ,
            ])
            ->assertCreated()
            ->assertJsonPath('current_company.name', 'Nova Empresa');

        $this->assertSame(2, User::query()->count());
        $this->assertSame(1, Account::query()->count());
        $company = ErpCompany::query()->where('cnpj', self::VALID_CNPJ)->firstOrFail();
        $this->assertSame('pending', $company->subscription->status);
        $this->assertNotEmpty($company->subscription->plan->modules);
    }

    public function test_public_signup_preserves_live_office_owner_reservation(): void
    {
        [$office, $link] = app(AccessAccounts::class)->issue([
            'name' => 'Reserved office', 'owner_name' => 'Reserved owner', 'login_email' => 'reserved-owner@example.test',
        ]);

        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/register', [
            'name' => 'Other owner', 'email' => ' RESERVED-OWNER@example.test ', 'password' => 'password123',
            'company_name' => 'Other company', 'cnpj' => self::VALID_CNPJ,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'reserved-owner@example.test']);
        $this->assertDatabaseMissing('erp_companies', ['cnpj' => self::VALID_CNPJ]);
        parse_str(parse_url($link['url'], PHP_URL_QUERY), $credential);
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/first-access', [
            ...$credential, 'password' => 'owner-password', 'password_confirmation' => 'owner-password',
        ])->assertCreated()->assertJsonPath('current_account.id', $office->id);
    }

    public function test_late_company_identity_collision_returns_validation_without_partial_owner(): void
    {
        ErpCompany::creating(function (ErpCompany $company): void {
            ErpCompany::withoutEvents(fn () => ErpCompany::factory()->create(['cnpj' => $company->cnpj]));
        });

        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/register', [
            'name' => 'Race owner', 'email' => 'cnpj-race@example.test', 'password' => 'password123',
            'company_name' => 'Race company', 'cnpj' => self::VALID_CNPJ,
        ])->assertUnprocessable()->assertJsonValidationErrors('cnpj');

        $this->assertDatabaseMissing('users', ['email' => 'cnpj-race@example.test']);
        $this->assertDatabaseCount('erp_subscriptions', 0);
    }

    #[DataProvider('inactiveReservations')]
    public function test_signup_accepts_inactive_office_owner_reservations(string $state): void
    {
        OwnerActivation::factory()->create([
            'email' => 'inactive-owner@example.test',
            ...match ($state) {
                'expired' => ['expires_at' => now()->subSecond()],
                'revoked' => ['revoked_at' => now()],
                'consumed' => ['consumed_at' => now()],
            },
        ]);
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/register', [
            'name' => 'Owner', 'email' => 'inactive-owner@example.test', 'password' => 'password123',
            'company_name' => 'Company', 'cnpj' => self::VALID_CNPJ,
        ])->assertCreated();
    }

    public static function inactiveReservations(): array
    {
        return [['expired'], ['revoked'], ['consumed']];
    }

    public function test_late_email_identity_collision_requires_login_without_partial_company(): void
    {
        User::creating(function (User $user): void {
            User::withoutEvents(fn () => User::factory()->create(['email' => $user->email]));
        });
        $response = $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/register', [
            'name' => 'Owner', 'email' => 'late-email@example.test', 'password' => 'secret-test-password',
            'company_name' => 'Company', 'cnpj' => self::VALID_CNPJ,
        ])->assertUnprocessable()->assertJsonPath('code', 'login_required')->assertJsonValidationErrors('email');

        $this->assertStringNotContainsString('secret-test-password', $response->getContent());
        $this->assertDatabaseMissing('users', ['email' => 'late-email@example.test']);
        $this->assertDatabaseMissing('erp_companies', ['cnpj' => self::VALID_CNPJ]);
    }

    public function test_unrelated_unique_collision_remains_service_failure(): void
    {
        $existing = User::firstOrFail();
        User::creating(function (User $user) use ($existing): void {
            $user->id = $existing->id;
        });
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/register', [
            'name' => 'Owner', 'email' => 'unrelated-key@example.test', 'password' => 'password123',
            'company_name' => 'Company', 'cnpj' => self::VALID_CNPJ,
        ])->assertStatus(503)->assertJsonMissingPath('errors');
        $this->assertDatabaseMissing('users', ['email' => 'unrelated-key@example.test']);
        $this->assertDatabaseMissing('erp_companies', ['cnpj' => self::VALID_CNPJ]);
    }

    public function test_authenticated_creation_maps_late_company_collision(): void
    {
        $user = User::factory()->create();
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk();
        ErpCompany::creating(function (ErpCompany $company): void {
            ErpCompany::withoutEvents(fn () => ErpCompany::factory()->create(['cnpj' => $company->cnpj]));
        });
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/erp/companies', [
            'name' => 'Company', 'cnpj' => self::VALID_CNPJ,
        ])->assertUnprocessable()->assertJsonValidationErrors('cnpj');
        $this->assertDatabaseCount('erp_subscriptions', 0);
    }

    public function test_existing_email_requires_login_without_mutation(): void
    {
        $existing = User::factory()->create(['email' => 'exists@example.test']);
        $hash = $existing->password;

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/register', [
                'name' => 'Other',
                'email' => 'exists@example.test',
                'password' => 'password123',
                'company_name' => 'Other Co',
                'cnpj' => '11222333000181',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'login_required');

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame(0, ErpCompany::query()->where('cnpj', '11222333000181')->count());
    }

    public function test_uninitialized_platform_rejects_public_registration(): void
    {
        DB::table('account_memberships')->delete();
        DB::table('accounts')->delete();
        DB::table('users')->delete();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/register', [
                'name' => 'Early',
                'email' => 'early@example.test',
                'password' => 'password123',
                'company_name' => 'Early Co',
                'cnpj' => self::VALID_CNPJ,
            ])
            ->assertForbidden();
    }

    public function test_authenticated_user_creates_company_with_pending_subscription(): void
    {
        $user = User::factory()->create();
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/companies', [
                'name' => 'Second Co',
                'cnpj' => self::VALID_CNPJ,
            ])
            ->assertCreated()
            ->assertJsonPath('current_company.name', 'Second Co');

        $companyId = ErpCompany::query()->where('cnpj', self::VALID_CNPJ)->value('id');
        $this->assertSame('pending', ErpSubscription::query()->where('erp_company_id', $companyId)->value('status'));
    }

    public function test_catalog_initialization_is_insert_only(): void
    {
        $catalog = app(ErpCommercialCatalog::class);
        $first = $catalog->ensureBasicPlan();
        $first->update(['name' => 'Custom ERP Plan']);
        $second = $catalog->ensureBasicPlan();
        $this->assertSame('Custom ERP Plan', $second->fresh()->name);
        $this->assertSame(1, ErpPlan::query()->count());
    }

    public function test_provisioning_rollback_leaves_no_partial_contract_on_duplicate_cnpj(): void
    {
        ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        $user = User::factory()->create();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/companies', ['name' => 'Dup', 'cnpj' => self::VALID_CNPJ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cnpj']);

        $this->assertSame(1, ErpCompany::query()->where('cnpj', self::VALID_CNPJ)->count());
        $this->assertSame(0, ErpCompany::query()->where('name', 'Dup')->count());
    }
}
