<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use App\Models\Erp\ErpSubscription;
use App\Models\OwnerActivation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\OfficeErpCommercialTestCase;

class TrialRegistrationTest extends OfficeErpCommercialTestCase
{
    public function test_public_current_route_requires_authentication_without_selected_company(): void
    {
        $this->getJson('/api/v1/erp/trials/current', ['Idempotency-Key' => 'trial-recovery-operation-1'])
            ->assertUnauthorized();
    }

    public function test_trial_registration_requires_exact_origin_and_csrf(): void
    {
        $this->withHeader('X-CSRF-Token', 'wrong')
            ->withHeader('Origin', 'https://hostile.example')
            ->postJson('/api/v1/erp/trials/'.str_repeat('a', 8).'-'.str_repeat('b', 4).'-'.str_repeat('c', 4).'-'.str_repeat('d', 4).'-'.str_repeat('e', 12).'/register', [])
            ->assertForbidden();

        $this->withHeader('Origin', 'http://localhost:3000')
            ->withoutHeader('X-CSRF-Token')
            ->postJson('/api/v1/erp/trials/'.str_repeat('a', 8).'-'.str_repeat('b', 4).'-'.str_repeat('c', 4).'-'.str_repeat('d', 4).'-'.str_repeat('e', 12).'/register', [])
            ->assertForbidden();
    }

    public function test_trial_throttle_has_retry_after(): void
    {
        $path = '/api/v1/erp/trials/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/register';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('X-Opmoni-Client-IP', '198.51.100.21')
                ->postJson($path, [])
                ->assertForbidden();
        }

        $this->withHeader('X-Opmoni-Client-IP', '198.51.100.21')
            ->postJson($path, [])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_authenticated_trial_registration_throttle_also_counts_by_actor(): void
    {
        $this->actingAs(User::factory()->create());
        $path = '/api/v1/erp/trials/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/register';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('X-Opmoni-Client-IP', '198.51.100.'.(30 + $attempt))
                ->withoutHeader('X-CSRF-Token')
                ->postJson($path, [])
                ->assertForbidden();
        }

        $this->withHeader('X-Opmoni-Client-IP', '198.51.100.35')
            ->withoutHeader('X-CSRF-Token')
            ->postJson($path, [])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_existing_email_without_login_requires_authentication_before_creating_trial_records(): void
    {
        config(['erp.office_trial_enabled' => true, 'erp.registration_enabled' => false]);
        $code = $this->enabledTrialLink();
        $existingOwner = User::factory()->create(['email' => 'already-registered@example.test']);
        $beforeUsers = User::query()->count();
        Http::fake();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => 'Pessoa responsável',
                'email' => $existingOwner->email,
                'company_name' => 'Empresa de teste',
                'cnpj' => '30288513000100',
                'tax_regime' => 'presumed_profit',
            ], ['Idempotency-Key' => 'existing-email-trial-operation'])
            ->assertConflict()
            ->assertJsonPath('code', 'login_required');

        self::assertSame($beforeUsers, User::query()->count());
        $this->assertDatabaseMissing('erp_companies', ['cnpj' => '30288513000100']);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        Http::assertNothingSent();
    }

    public function test_pending_platform_owner_activation_reserves_email_from_trial_signup_without_consuming_activation(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $activationToken = str_repeat('a', 64);
        $activation = OwnerActivation::factory()->create([
            'email' => 'reserved-trial-email@example.test',
            'token_hash' => hash('sha256', $activationToken),
            'expires_at' => now()->addDays(2),
        ]);
        Http::fake();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => 'Pessoa responsável',
                'email' => $activation->email,
                'password' => 'never-store-this-password',
                'company_name' => 'Empresa de teste',
                'cnpj' => '27865757000102',
                'tax_regime' => 'presumed_profit',
            ], ['Idempotency-Key' => 'trial-pending-activation-operation'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->getJson('/api/v1/first-access/status?'.http_build_query([
            'token' => $activationToken,
            'email' => $activation->email,
        ]))->assertOk()->assertJsonPath('valid', true);
        self::assertDatabaseMissing('users', ['email' => $activation->email]);
        $this->assertDatabaseCount('erp_companies', 0);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
        Http::assertNothingSent();
    }

    public function test_public_signup_atomically_provisions_a_seven_day_trial_and_current_recovers_it(): void
    {
        config(['erp.office_trial_enabled' => true, 'erp.registration_enabled' => false]);
        $code = $this->enabledTrialLink();
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload())]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $key = 'trial-success-operation-0001';
        $password = 'never-store-this-password';

        $response = $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => 'Pessoa responsável',
                'email' => 'new-trial-owner@example.test',
                'password' => $password,
                'company_name' => 'Globo Comunicação',
                'cnpj' => '27.865.757/0001-02',
                'tax_regime' => 'presumed_profit',
            ], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeader('Cache-Control');
        self::assertContains('no-store', array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertSame('trialing', $data['status']);
        self::assertEquals(168, CarbonImmutable::parse($data['started_at'])->diffInHours(CarbonImmutable::parse($data['ends_at'])));
        self::assertSame(0, CarbonImmutable::parse($data['started_at'])->utcOffset());

        $owner = User::query()->where('email', 'new-trial-owner@example.test')->firstOrFail();
        $companyMembership = ErpCompanyMembership::query()
            ->where('erp_company_id', $data['company_id'])
            ->where('user_id', $owner->id)
            ->where('role', 'owner')
            ->firstOrFail();
        self::assertSame((int) $data['company_id'], (int) $companyMembership->erp_company_id);
        $this->assertDatabaseMissing('account_memberships', ['user_id' => $owner->id]);
        $accountId = OfficeErpTrialLink::query()->where('public_code', $code)->value('account_id');
        $this->assertDatabaseHas('clients', ['id' => $data['client_id'], 'account_id' => $accountId]);
        $this->assertDatabaseHas('erp_subscriptions', ['id' => $data['subscription_id'], 'status' => 'trialing']);
        $this->assertDatabaseHas('erp_subscription_trials', ['id' => $data['trial_id'], 'cnpj' => '27865757000102']);
        $this->assertDatabaseHas('office_erp_contracts', [
            'id' => $data['contract_id'],
            'agreement_status' => 'accepted',
            'effective_at' => null,
            'service_anchor_on' => null,
        ]);
        $this->assertDatabaseHas('office_erp_licence_allocations', [
            'contract_id' => $data['contract_id'],
            'erp_company_id' => $data['company_id'],
            'state' => 'occupied',
        ]);
        $this->assertDatabaseCount('office_erp_invoices', 0);

        $operation = OfficeErpCommercialOperation::query()->where('idempotency_key', $key)->firstOrFail();
        self::assertStringNotContainsString($password, json_encode($operation->payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($password, json_encode($operation->outcome, JSON_THROW_ON_ERROR));
        $this->assertAuthenticatedAs($owner);
        $this->assertSame((int) $data['company_id'], (int) $this->app['session.store']->get('current_company_id'));

        $current = $this->getJson('/api/v1/erp/trials/current', ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.trial_id', $data['trial_id'])
            ->assertJsonPath('data.company_id', $data['company_id']);
        self::assertContains('no-store', array_map('trim', explode(',', (string) $current->headers->get('Cache-Control'))));

        CarbonImmutable::setTestNow(CarbonImmutable::parse($data['ends_at'])->addSecond());
        try {
            $this->getJson('/api/v1/erp/trials/current', ['Idempotency-Key' => $key])
                ->assertOk()
                ->assertJsonPath('data.trial_id', $data['trial_id']);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $companyMembership->forceFill(['role' => 'admin'])->save();
        $this->actingAs($owner)
            ->getJson('/api/v1/erp/trials/current', ['Idempotency-Key' => $key])
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/erp/trials/current', ['Idempotency-Key' => $key])
            ->assertNotFound();
    }

    public function test_provider_failure_leaves_identity_and_trial_aggregate_unmodified(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response([], 503)]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => 'Pessoa responsável',
                'email' => 'provider-failure@example.test',
                'password' => 'never-store-this-password',
                'company_name' => 'Empresa de teste',
                'cnpj' => '27865757000102',
                'tax_regime' => 'presumed_profit',
            ], ['Idempotency-Key' => 'trial-provider-failure-operation'])
            ->assertStatus(503);

        $this->assertDatabaseMissing('users', ['email' => 'provider-failure@example.test']);
        $this->assertDatabaseMissing('erp_companies', ['cnpj' => '27865757000102']);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }

    public function test_authenticated_owner_can_reuse_an_active_same_office_client_after_confirmation(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $accountId = (int) OfficeErpTrialLink::query()->where('public_code', $code)->value('account_id');
        $client = Client::factory()->company()->create([
            'account_id' => $accountId,
            'tax_id' => '27865757000102',
            'name' => 'Carteira existente',
            'status' => 'active',
            'tax_regime' => 'presumed_profit',
        ]);
        $owner = User::factory()->create(['email' => 'reusing-owner@example.test']);
        $this->actingAs($owner);
        $this->withSession(['access_deadline' => time() + 1800, 'access_authenticated' => true]);
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload())]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $response = $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => $owner->name,
                'email' => $owner->email,
                'company_name' => 'Empresa ERP',
                'cnpj' => '27865757000102',
                'tax_regime' => 'presumed_profit',
                'confirm_existing_association' => true,
            ], ['Idempotency-Key' => 'trial-client-reuse-operation'])
            ->assertCreated();

        self::assertSame((int) $client->id, (int) $response->json('data.client_id'));
        self::assertSame('Carteira existente', $client->fresh()->name);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseMissing('account_memberships', ['account_id' => $accountId, 'user_id' => $owner->id]);
    }

    public function test_active_client_requires_explicit_confirmation_and_archived_client_is_never_restored(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $accountId = (int) OfficeErpTrialLink::query()->where('public_code', $code)->value('account_id');
        $client = Client::factory()->company()->create([
            'account_id' => $accountId,
            'tax_id' => '27865757000102',
            'name' => 'Cliente existente',
            'status' => 'active',
            'tax_regime' => 'presumed_profit',
        ]);
        $owner = User::factory()->create(['email' => 'confirm-client-owner@example.test']);
        $this->actingAs($owner)->withSession(['access_deadline' => time() + 1800, 'access_authenticated' => true]);
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload())]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $payload = [
            'name' => $owner->name,
            'email' => $owner->email,
            'company_name' => 'Empresa ERP',
            'cnpj' => '27865757000102',
            'tax_regime' => 'presumed_profit',
        ];

        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', $payload, ['Idempotency-Key' => 'trial-client-needs-confirmation'])
            ->assertConflict();

        $client->delete();
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', $payload + ['confirm_existing_association' => true], ['Idempotency-Key' => 'trial-client-is-archived'])
            ->assertConflict();

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseCount('erp_companies', 0);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
    }

    public function test_cannot_attach_a_company_already_owned_outside_the_linked_office(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $foreignOwner = User::factory()->create();
        $foreignCompany = ErpCompany::factory()->create(['cnpj' => '27865757000102']);
        ErpCompanyMembership::query()->create([
            'erp_company_id' => $foreignCompany->id,
            'user_id' => $foreignOwner->id,
            'role' => 'owner',
        ]);
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload())]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => 'Novo responsável',
                'email' => 'foreign-company-owner@example.test',
                'password' => 'never-store-this-password',
                'company_name' => 'Empresa existente',
                'cnpj' => '27865757000102',
                'tax_regime' => 'presumed_profit',
            ], ['Idempotency-Key' => 'trial-foreign-company-operation'])
            ->assertConflict();

        $this->assertDatabaseMissing('users', ['email' => 'foreign-company-owner@example.test']);
        $this->assertDatabaseHas('erp_companies', ['id' => $foreignCompany->id, 'cnpj' => '27865757000102']);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        $this->assertDatabaseCount('office_erp_contracts', 0);
    }

    public function test_committed_trial_recovers_from_session_failure_by_login_and_current_without_resubmitting_signup(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $password = 'private-trial-password';
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload())]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $key = 'trial-session-recovery-operation';
        $originalCache = Cache::getFacadeRoot();
        $originalStore = Cache::store()->getStore();
        Cache::swap(new class($originalStore) extends Repository
        {
            public function put($key, $value, $ttl = null): bool
            {
                if (str_starts_with((string) $key, 'revoked:')) {
                    throw new \RuntimeException('session cache unavailable');
                }

                return parent::put($key, $value, $ttl);
            }
        });

        try {
            $this->withHeader('X-CSRF-Token', $csrf)
                ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                    'name' => 'Pessoa responsável',
                    'email' => 'session-recovery-owner@example.test',
                    'password' => $password,
                    'company_name' => 'Globo Comunicação',
                    'cnpj' => '27.865.757/0001-02',
                    'tax_regime' => 'presumed_profit',
                ], ['Idempotency-Key' => $key])
                ->assertStatus(503)
                ->assertJsonPath('message', 'Serviço temporariamente indisponível. Tente novamente.');
        } finally {
            Cache::swap($originalCache);
        }

        $owner = User::query()->where('email', 'session-recovery-owner@example.test')->firstOrFail();
        self::assertTrue(Hash::check($password, $owner->password));
        $this->assertDatabaseCount('erp_subscription_trials', 1);
        $this->assertDatabaseCount('office_erp_commercial_operations', 1);
        $operation = OfficeErpCommercialOperation::query()->where('idempotency_key', $key)->firstOrFail();
        self::assertStringNotContainsString($password, json_encode($operation->payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($password, json_encode($operation->outcome, JSON_THROW_ON_ERROR));

        $loginCsrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $loginCsrf)
            ->postJson('/api/v1/login', ['email' => $owner->email, 'password' => $password])
            ->assertOk();
        $this->getJson('/api/v1/erp/trials/current', ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.trial_id', $operation->outcome['data']['trial_id']);

        $this->assertDatabaseCount('erp_subscription_trials', 1);
        $this->assertDatabaseCount('office_erp_commercial_operations', 1);
        Http::assertSentCount(1);
    }

    public function test_capacity_and_client_limits_are_rechecked_before_trial_writes(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $accountId = (int) OfficeErpTrialLink::query()->where('public_code', $code)->value('account_id');
        $account = Account::query()->findOrFail($accountId);
        $plan = Plan::query()->findOrFail($account->subscription->plan_id);
        $plan->forceFill(['erp_commercial' => ['enabled' => true, 'licence_limit' => 0, 'module_keys' => ['purchases']]])->save();
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response([], 404)]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $payload = [
            'name' => 'Pessoa responsável',
            'email' => 'capacity-trial-owner@example.test',
            'password' => 'never-store-this-password',
            'company_name' => 'Empresa de teste',
            'cnpj' => '27865757000102',
            'tax_regime' => 'presumed_profit',
        ];

        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', $payload, ['Idempotency-Key' => 'trial-capacity-rejected-operation'])
            ->assertForbidden();

        self::assertDatabaseMissing('users', ['email' => 'capacity-trial-owner@example.test']);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);

        $plan->forceFill(['erp_commercial' => ['enabled' => true, 'licence_limit' => 5, 'module_keys' => ['purchases']]])->save();
        $plan->forceFill(['limits' => ['clients' => 0]])->save();
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [...$payload, 'email' => 'client-limit-trial-owner@example.test'], ['Idempotency-Key' => 'trial-client-limit-rejected-operation'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        self::assertDatabaseMissing('users', ['email' => 'client-limit-trial-owner@example.test']);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }

    public function test_public_trial_rejects_client_id_and_rechecks_exact_trial_link_revision_after_provider_preflight(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $accountId = (int) OfficeErpTrialLink::query()->where('public_code', $code)->value('account_id');
        $foreignAccount = Account::factory()->create(['status' => 'active']);
        $foreignClient = Client::factory()->company()->create(['account_id' => $foreignAccount->id]);
        $archivedClient = Client::factory()->company()->create(['account_id' => $accountId]);
        $archivedClient->delete();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $payload = [
            'name' => 'Pessoa responsável',
            'email' => 'revision-trial-owner@example.test',
            'password' => 'never-store-this-password',
            'company_name' => 'Empresa de teste',
            'cnpj' => '27865757000102',
            'tax_regime' => 'presumed_profit',
        ];
        foreach ([$foreignClient, $archivedClient] as $index => $client) {
            $this->withHeader('X-CSRF-Token', $csrf)
                ->postJson('/api/v1/erp/trials/'.$code.'/register', [...$payload, 'client_id' => $client->id], [
                    'Idempotency-Key' => 'trial-client-id-rejected-'.$index.'-operation',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('client_id');
        }

        $link = OfficeErpTrialLink::query()->where('public_code', $code)->firstOrFail();
        Http::fake(function () use ($link) {
            $link->increment('revision');

            return Http::response($this->providerPayload());
        });
        unset($payload['client_id']);
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', $payload, ['Idempotency-Key' => 'trial-revision-changed-operation'])
            ->assertConflict();
        self::assertSame(2, (int) $link->fresh()->revision);

        $this->assertDatabaseMissing('users', ['email' => 'revision-trial-owner@example.test']);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }

    public function test_current_rejects_an_outcome_whose_subscription_no_longer_matches_trial_company(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response($this->providerPayload())]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $key = 'trial-current-inconsistent-outcome';
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => 'Pessoa responsável',
                'email' => 'current-inconsistent-owner@example.test',
                'password' => 'never-store-this-password',
                'company_name' => 'Globo Comunicação',
                'cnpj' => '27865757000102',
                'tax_regime' => 'presumed_profit',
            ], ['Idempotency-Key' => $key])
            ->assertCreated();

        $operation = OfficeErpCommercialOperation::query()->where('idempotency_key', $key)->firstOrFail();
        $otherCompany = ErpCompany::factory()->create();
        $otherSubscription = ErpSubscription::factory()->create(['erp_company_id' => $otherCompany->id]);
        $outcome = $operation->outcome;
        $outcome['data']['subscription_id'] = (int) $otherSubscription->id;
        $operation->forceFill(['outcome' => $outcome])->save();

        $this->getJson('/api/v1/erp/trials/current', ['Idempotency-Key' => $key])
            ->assertNotFound();
    }

    public function test_technical_plan_module_change_during_provider_preflight_aborts_trial_registration(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $link = OfficeErpTrialLink::query()->where('public_code', $code)->firstOrFail();
        Http::fake(function () use ($link) {
            ErpPlanModule::query()->where('erp_plan_id', $link->erp_plan_id)->delete();

            return Http::response([], 404);
        });
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => 'Pessoa responsável',
                'email' => 'plan-modules-changed-owner@example.test',
                'password' => 'never-store-this-password',
                'company_name' => 'Empresa de teste',
                'cnpj' => '27865757000102',
                'tax_regime' => 'presumed_profit',
            ], ['Idempotency-Key' => 'trial-plan-modules-changed-operation'])
            ->assertConflict();

        $this->assertDatabaseMissing('users', ['email' => 'plan-modules-changed-owner@example.test']);
        $this->assertDatabaseMissing('clients', ['tax_id' => '27865757000102']);
        $this->assertDatabaseCount('erp_companies', 0);
        $this->assertDatabaseCount('erp_subscription_trials', 0);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }

    public function test_authenticated_owner_can_register_trial_for_company_already_owned_by_them(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $code = $this->enabledTrialLink();
        $accountId = (int) OfficeErpTrialLink::query()->where('public_code', $code)->value('account_id');
        $client = Client::factory()->company()->create([
            'account_id' => $accountId,
            'tax_id' => '27865757000102',
            'status' => 'active',
            'tax_regime' => 'presumed_profit',
        ]);
        $owner = User::factory()->create(['email' => 'existing-company-trial-owner@example.test']);
        $company = ErpCompany::factory()->create(['cnpj' => '27865757000102']);
        ErpCompanyMembership::query()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $subscription = ErpSubscription::factory()->create(['erp_company_id' => $company->id]);
        $this->actingAs($owner)->withSession(['access_deadline' => time() + 1800, 'access_authenticated' => true]);
        Http::fake(['publica.cnpj.ws/cnpj/27865757000102' => Http::response([], 404)]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $response = $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/trials/'.$code.'/register', [
                'name' => $owner->name,
                'email' => $owner->email,
                'company_name' => $company->name,
                'cnpj' => '27865757000102',
                'tax_regime' => 'presumed_profit',
                'confirm_existing_association' => true,
            ], ['Idempotency-Key' => 'trial-existing-company-owner-operation'])
            ->assertCreated();

        self::assertSame((int) $client->id, (int) $response->json('data.client_id'));
        self::assertSame((int) $company->id, (int) $response->json('data.company_id'));
        self::assertSame((int) $subscription->id, (int) $response->json('data.subscription_id'));
        $this->assertDatabaseHas('erp_subscription_trials', ['erp_company_id' => $company->id, 'account_id' => $accountId]);
        $this->assertDatabaseCount('erp_companies', 1);
        $this->assertDatabaseCount('erp_subscription_trials', 1);
    }

    private function enabledTrialLink(): string
    {
        $account = Account::factory()->create(['status' => 'active']);
        $officePlan = Plan::factory()->create([
            'erp_commercial' => [
                'enabled' => true,
                'licence_limit' => 5,
                'module_keys' => ['purchases'],
            ],
        ]);
        $subscription = $account->subscription()->first() ?? Subscription::factory()->create(['account_id' => $account->id]);
        $subscription->forceFill(['plan_id' => $officePlan->id, 'status' => 'active'])->save();
        $erpPlan = ErpPlan::query()->create(['slug' => 'trial-registration-test', 'name' => 'Plano de teste']);
        ErpPlanModule::query()->create([
            'erp_plan_id' => $erpPlan->id,
            'module_key' => 'purchases',
            'quota' => null,
        ]);
        $code = (string) str()->uuid();
        OfficeErpTrialLink::query()->create([
            'account_id' => $account->id,
            'public_code' => $code,
            'erp_plan_id' => $erpPlan->id,
            'enabled' => true,
            'revision' => 1,
        ]);

        return $code;
    }

    /** @return array<string, mixed> */
    private function providerPayload(): array
    {
        return [
            'razao_social' => 'GLOBO COMUNICACAO E PARTICIPACOES S/A',
            'porte' => ['descricao' => 'Demais'],
            'natureza_juridica' => ['descricao' => 'Sociedade Anônima Fechada'],
            'simples' => ['mei' => 'Não', 'simples' => 'Não'],
            'estabelecimento' => [
                'cnpj' => '27865757000102',
                'nome_fantasia' => 'GLOBOPLAY',
                'situacao_cadastral' => 'Ativa',
                'data_situacao_cadastral' => '2005-11-03',
                'data_inicio_atividade' => '1986-01-31',
                'tipo_logradouro' => 'RUA',
                'logradouro' => 'LOPES QUINTAS',
                'numero' => '303',
                'complemento' => null,
                'bairro' => 'JARDIM BOTANICO',
                'cep' => '22460901',
                'ddd1' => '21',
                'telefone1' => '21554551',
                'email' => 'fiscal@example.test',
                'atualizado_em' => '2026-09-12T03:00:00.000Z',
                'atividade_principal' => ['id' => '6021700', 'descricao' => 'Atividades de televisão aberta'],
                'estado' => ['sigla' => 'RJ'],
                'cidade' => ['nome' => 'Rio de Janeiro'],
            ],
        ];
    }
}
