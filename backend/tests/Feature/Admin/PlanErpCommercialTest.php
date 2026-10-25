<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BusinessTestCase;

class PlanErpCommercialTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_legacy_plan_resale_is_explicitly_disabled_in_raw_resources(): void
    {
        $plan = Plan::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $expected = ['enabled' => false, 'licence_limit' => 0, 'module_keys' => []];
        $this->getJson('/api/v1/admin/plans')->assertOk()->assertJsonPath('0.erp_commercial', $expected)->assertJsonMissingPath('data');
        $this->getJson('/api/v1/admin/plans/'.$plan->id)->assertOk()->assertJsonPath('erp_commercial', $expected)->assertJsonMissingPath('data');
    }

    public function test_create_accepts_finite_resale_allowance_and_returns_raw_plan(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $allowance = ['enabled' => true, 'licence_limit' => 3, 'module_keys' => ['purchases', 'cte']];
        $response = $this->postJson('/api/v1/admin/plans', [
            'slug' => 'resale-plan', 'name' => 'Resale plan', 'limits' => ['users' => 4], 'erp_commercial' => $allowance,
        ])->assertCreated()->assertJsonPath('erp_commercial', $allowance)->assertJsonMissingPath('data');

        $this->assertSame($allowance, Plan::findOrFail($response->json('id'))->erp_commercial);
    }

    public function test_update_accepts_unlimited_resale_and_legacy_edits_preserve_it(): void
    {
        $plan = Plan::factory()->create(['limits' => ['users' => 8, 'clients' => 9, 'monitorings' => 10]]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $allowance = ['enabled' => true, 'licence_limit' => null, 'module_keys' => []];
        $this->patchJson('/api/v1/admin/plans/'.$plan->id, ['erp_commercial' => $allowance])
            ->assertOk()->assertJsonPath('erp_commercial', $allowance);
        $this->patchJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Updated', 'limits' => ['users' => 0]])
            ->assertOk()->assertJsonPath('erp_commercial', $allowance)
            ->assertJsonPath('limits', ['users' => 0, 'clients' => null, 'monitorings' => null]);
        $this->assertSame($allowance, $plan->fresh()->erp_commercial);
    }

    public function test_legacy_create_without_resale_allowance_remains_valid_and_disabled(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->postJson('/api/v1/admin/plans', ['slug' => 'legacy-plan', 'name' => 'Legacy plan', 'limits' => []])
            ->assertCreated()->assertJsonPath('erp_commercial', ['enabled' => false, 'licence_limit' => 0, 'module_keys' => []]);
    }

    #[DataProvider('invalidAllowances')]
    public function test_invalid_update_is_atomic(array $allowance, string $field): void
    {
        $prior = ['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']];
        $plan = Plan::factory()->create(['name' => 'Original', 'limits' => ['users' => 7], 'erp_commercial' => $prior]);
        $before = $plan->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->json('PATCH', '/api/v1/admin/plans/'.$plan->id, [
            'name' => 'Changed', 'limits' => ['users' => 1], 'erp_commercial' => $allowance,
        ], [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $plan->fresh()->getAttributes());
    }

    #[DataProvider('invalidAllowances')]
    public function test_invalid_create_does_not_create_plan(array $allowance, string $field): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->json('POST', '/api/v1/admin/plans', [
            'slug' => 'invalid-resale', 'name' => 'Invalid resale', 'limits' => [], 'erp_commercial' => $allowance,
        ], [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseMissing('plans', ['slug' => 'invalid-resale']);
    }

    public static function invalidAllowances(): array
    {
        $valid = ['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']];

        return [
            'unknown module' => [[...$valid, 'module_keys' => ['unknown']], 'erp_commercial.module_keys.0'],
            'duplicate module' => [[...$valid, 'module_keys' => ['purchases', 'purchases']], 'erp_commercial.module_keys.0'],
            'fraction capacity' => [[...$valid, 'licence_limit' => 1.5], 'erp_commercial.licence_limit'],
            'negative capacity' => [[...$valid, 'licence_limit' => -1], 'erp_commercial.licence_limit'],
            'string capacity' => [[...$valid, 'licence_limit' => '2'], 'erp_commercial.licence_limit'],
            'integer float capacity' => [[...$valid, 'licence_limit' => 2.0], 'erp_commercial.licence_limit'],
            'integer boolean capacity' => [[...$valid, 'licence_limit' => true], 'erp_commercial.licence_limit'],
            'integer enabled' => [[...$valid, 'enabled' => 1], 'erp_commercial.enabled'],
            'string enabled' => [[...$valid, 'enabled' => 'true'], 'erp_commercial.enabled'],
            'missing enabled' => [['licence_limit' => 2, 'module_keys' => []], 'erp_commercial'],
            'missing capacity' => [['enabled' => true, 'module_keys' => []], 'erp_commercial'],
            'missing modules' => [['enabled' => true, 'licence_limit' => 2], 'erp_commercial'],
            'unknown field' => [[...$valid, 'unknown' => true], 'erp_commercial'],
            'non-list modules' => [[...$valid, 'module_keys' => ['first' => 'purchases']], 'erp_commercial.module_keys'],
        ];
    }
}
