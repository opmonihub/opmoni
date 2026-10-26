<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
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
