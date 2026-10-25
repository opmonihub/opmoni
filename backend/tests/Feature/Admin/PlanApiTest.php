<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BusinessTestCase;

class PlanApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_name_only_edit_preserves_limits_and_raw_response(): void
    {
        $plan = Plan::factory()->create(['limits' => ['users' => 5, 'clients' => 50]]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->patchJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Updated'])
            ->assertOk()->assertJsonPath('id', $plan->id)->assertJsonPath('slug', $plan->slug)
            ->assertJsonPath('limits', ['users' => 5, 'clients' => 50])->assertJsonMissingPath('data');
        $list = $this->getJson('/api/v1/admin/plans')->assertOk()->assertJsonMissingPath('data');
        $ids = array_column($list->json(), 'id');
        $sortedIds = $ids;
        sort($sortedIds, SORT_NUMERIC);
        $this->assertSame($sortedIds, $ids);
        $this->assertSame($plan->id, end($ids));
        $this->getJson('/api/v1/admin/plans/'.$plan->id)->assertOk()->assertJsonPath('id', $plan->id)->assertJsonPath('name', 'Updated')->assertJsonMissingPath('data');
    }

    #[DataProvider('validLimits')]
    public function test_supplied_limits_replace_and_normalize_known_nullable_keys(array $limits, array $expected): void
    {
        $plan = Plan::factory()->create(['limits' => ['users' => 99, 'clients' => 99, 'monitorings' => 99]]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->patchJson('/api/v1/admin/plans/'.$plan->id, ['limits' => $limits])
            ->assertOk()->assertJsonPath('limits', $expected);
        $this->assertSame($expected, $plan->fresh()->limits);
    }

    public static function validLimits(): array
    {
        return [
            'empty' => [[], ['users' => null, 'clients' => null, 'monitorings' => null]],
            'sparse' => [['users' => 0], ['users' => 0, 'clients' => null, 'monitorings' => null]],
            'null values' => [['clients' => null], ['users' => null, 'clients' => null, 'monitorings' => null]],
            'full' => [['users' => 5, 'clients' => 50, 'monitorings' => 12], ['users' => 5, 'clients' => 50, 'monitorings' => 12]],
        ];
    }

    #[DataProvider('invalidUpdates')]
    public function test_invalid_updates_are_rejected_without_mutation(array $payload, string $field): void
    {
        $plan = Plan::factory()->create(['name' => 'Original', 'limits' => ['users' => 7]]);
        $before = $plan->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->patchJson('/api/v1/admin/plans/'.$plan->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $plan->fresh()->getAttributes());
    }

    public static function invalidUpdates(): array
    {
        return [
            'negative' => [['name' => 'Changed', 'limits' => ['users' => -1]], 'limits.users'],
            'fraction' => [['limits' => ['clients' => 1.5]], 'limits.clients'],
            'string' => [['limits' => ['users' => '5']], 'limits.users'],
            'bool' => [['limits' => ['users' => true]], 'limits.users'],
            'unknown limit' => [['limits' => ['other' => 5]], 'limits'],
            'null container' => [['limits' => null], 'limits'],
            'short name' => [['name' => 'A'], 'name'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'empty name' => [['name' => ''], 'name'],
            'slug edit' => [['slug' => 'new-slug'], 'slug'],
            'empty slug edit' => [['slug' => null], 'slug'],
            'unexpected field' => [['unexpected' => 'value'], 'unexpected'],
        ];
    }

    public function test_plan_name_accepts_both_length_boundaries(): void
    {
        $plan = Plan::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        foreach (['AB', str_repeat('a', 255)] as $name) {
            $this->patchJson('/api/v1/admin/plans/'.$plan->id, ['name' => $name])->assertOk()->assertJsonPath('name', $name);
        }
    }

    public function test_integral_json_float_is_not_a_plan_limit(): void
    {
        $plan = Plan::factory()->create();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->json('PATCH', '/api/v1/admin/plans/'.$plan->id, ['limits' => ['users' => 5.0]], [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors('limits.users');
    }
}
