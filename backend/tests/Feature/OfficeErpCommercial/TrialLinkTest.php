<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use App\Models\User;
use Tests\OfficeErpCommercialTestCase;

final class TrialLinkTest extends OfficeErpCommercialTestCase
{
    public function test_selected_office_admin_can_configure_rotate_and_read_public_safe_availability(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 3, 'module_keys' => ['purchases']]);
        $technicalPlan = $this->technicalPlan();
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $this->getJson('/api/v1/erp-commercial/trial-link')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.plans.0.id', $technicalPlan->id)
            ->assertJsonPath('data.plans.0.module_keys', ['purchases']);
        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => true,
        ], ['Idempotency-Key' => '11111111-1111-4111-8111-111111111111'])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.plan.name', 'ERP Básico Teste');

        $link = OfficeErpTrialLink::query()->where('account_id', $ctx->accountId)->firstOrFail();
        $code = (string) $link->public_code;
        $public = $this->getJson('/api/v1/erp/trials/'.$code)
            ->assertOk()
            ->assertJsonPath('data.duration_days', 7)
            ->assertJsonPath('data.available', true)
            ->assertJsonMissingPath('data.clients')
            ->assertJsonMissingPath('data.account_id');

        self::assertSame((string) $ctx->account->name, $public->json('data.sponsor.name'));
        self::assertStringContainsString('no-store', (string) $public->headers->get('Cache-Control'));
        self::assertStringEndsWith('/erp/testar/'.$code, (string) $this->getJson('/api/v1/erp-commercial/trial-link')->json('data.url'));

        $this->postJson('/api/v1/erp-commercial/trial-link/rotate', [], [
            'Idempotency-Key' => '22222222-2222-4222-8222-222222222222',
        ])->assertOk()->assertJsonPath('data.revision', 2);

        $rotated = $link->fresh();
        self::assertNotSame($code, $rotated->public_code);
        self::assertSame(2, (int) $rotated->revision);
        $this->getJson('/api/v1/erp/trials/'.$code)->assertNotFound();
        $this->getJson('/api/v1/erp/trials/'.$rotated->public_code)->assertOk()->assertJsonPath('data.available', true);
    }

    public function test_link_configuration_is_scoped_to_selected_real_office_admin(): void
    {
        config(['erp.office_trial_enabled' => true]);
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $technicalPlan = $this->technicalPlan();
        $other = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => true,
        ], ['Idempotency-Key' => '33333333-3333-4333-8333-333333333333'])->assertOk();

        $this->withHeader('X-Account-Id', (string) $other->accountId)
            ->getJson('/api/v1/erp-commercial/trial-link')
            ->assertConflict()
            ->assertJsonPath('code', 'account_context_changed');

        $operator = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $ctx->accountId,
            'user_id' => $operator->id,
            'role' => 'operador',
        ]);
        $this->selectBusinessAccount($operator, $ctx->accountId);
        $this->actingAs($operator);
        $this->withHeader('X-Account-Id', (string) $ctx->accountId)
            ->getJson('/api/v1/erp-commercial/trial-link')
            ->assertForbidden();
    }

    public function test_release_flag_wrong_near_prefix_and_zero_capacity_are_safe(): void
    {
        config(['erp.office_trial_enabled' => false]);
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 0, 'module_keys' => ['purchases']]);
        $technicalPlan = $this->technicalPlan();
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $this->putJson('/api/v1/erp-commercial/trial-link', [
            'erp_plan_id' => $technicalPlan->id,
            'enabled' => true,
        ], ['Idempotency-Key' => '44444444-4444-4444-8444-444444444444'])->assertOk();

        $code = (string) OfficeErpTrialLink::query()->where('account_id', $ctx->accountId)->value('public_code');
        $this->getJson('/api/v1/erp/trials/'.$code)->assertNotFound();
        config(['erp.office_trial_enabled' => true]);
        $this->getJson('/api/v1/erp/trials/'.substr($code, 0, -1).'x')->assertNotFound();
        $this->getJson('/api/v1/erp/trials/'.$code)->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'licence_capacity_reached');

        config(['erp.office_trial_enabled' => false]);
        $this->getJson('/api/v1/erp/trials/'.$code)->assertNotFound();
    }

    private function technicalPlan(): ErpPlan
    {
        $plan = ErpPlan::factory()->create(['name' => 'ERP Básico Teste']);
        ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => 'purchases']);

        return $plan->load('modules');
    }
}
