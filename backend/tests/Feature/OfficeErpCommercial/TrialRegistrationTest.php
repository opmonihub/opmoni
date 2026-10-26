<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
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
    }

    private function enabledTrialLink(): string
    {
        $account = Account::factory()->create(['status' => 'active']);
        $officePlan = Plan::factory()->create([
            'erp_commercial' => [
                'enabled' => false,
                'licence_limit' => 5,
                'module_keys' => ['purchases'],
            ],
        ]);
        $subscription = $account->subscription()->first() ?? Subscription::factory()->create(['account_id' => $account->id]);
        $subscription->forceFill(['plan_id' => $officePlan->id, 'status' => 'active'])->save();
        $erpPlan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
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
}
