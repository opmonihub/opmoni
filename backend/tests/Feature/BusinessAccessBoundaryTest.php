<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class BusinessAccessBoundaryTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_super_admin_requires_real_membership_to_read_business_data(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $account->id);
        $this->actingAs($superAdmin)->getJson('/api/v1/clients')
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_super_admin_with_read_only_membership_cannot_write_business_data(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $superAdmin->id, 'role' => 'user']);
        $this->actingAs($superAdmin)->postJson('/api/v1/tags', ['name' => 'Unauthorized'])
            ->assertForbidden();
        $this->assertDatabaseCount('tags', 0);
    }

    public function test_account_selection_is_session_scoped_and_never_persisted_on_user(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $user = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAs($user)->getJson('/api/v1/clients')->assertOk();
        $this->assertArrayNotHasKey('current_account_id', $user->fresh()->getAttributes());
        $this->assertSame($account->id, $this->app['session.store']->get('current_account_id'));
    }
}
