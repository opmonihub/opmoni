<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Tag;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class ClientSavedFilterTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_saved_filters_belong_to_the_user_inside_the_account(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $owner = $this->memberOf($account, 'operador');
        $peer = $this->memberOf($account, 'operador');
        $tag = Tag::factory()->create(['account_id' => $account->getKey(), 'name' => 'Prioridade']);

        $this->actingAs($owner, 'web');

        $id = $this->postJson('/api/v1/clients/saved-filters', [
            'name' => '  Simples  ',
            'q' => '  amaral  ',
            'filters' => [[
                'columnId' => 'regime',
                'operator' => 'is',
                'values' => ['simple_national'],
            ], [
                'columnId' => 'tag',
                'operator' => 'is any of',
                'values' => [(string) $tag->getKey()],
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Simples')
            ->assertJsonPath('data.q', 'amaral')
            ->json('data.id');

        $this->getJson('/api/v1/clients/saved-filters')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Simples');

        $this->actingAs($peer, 'web')
            ->getJson('/api/v1/clients/saved-filters')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($peer, 'web')
            ->deleteJson('/api/v1/clients/saved-filters/'.$id)
            ->assertNotFound();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/clients/saved-filters', [
                'name' => 'Simples',
                'filters' => [[
                    'columnId' => 'status',
                    'operator' => 'is not',
                    'values' => ['inactive'],
                ]],
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->deleteJson('/api/v1/clients/saved-filters/'.$id)->assertNoContent();
        $this->getJson('/api/v1/clients/saved-filters')->assertJsonCount(0, 'data');
    }

    public function test_saved_filter_rejects_unknown_values(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $this->actingAs($this->memberOf($account), 'web');

        $this->postJson('/api/v1/clients/saved-filters', [
            'name' => 'Ruim',
            'filters' => [[
                'columnId' => 'certificate',
                'operator' => 'is',
                'values' => ['nope'],
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('filters.0.values.0');
    }

    public function test_read_only_member_cannot_persist_a_saved_filter(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account, 'user'))->postJson('/api/v1/clients/saved-filters', ['name' => 'Read only', 'q' => 'local', 'filters' => []])->assertForbidden();
        $this->assertDatabaseCount('client_saved_filters', 0);
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
