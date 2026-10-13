<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class ClientTagTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_tags_are_scoped_to_the_account_and_appear_on_clients(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $other = Account::factory()->create();
        $client = Client::factory()->create(['account_id' => $account->getKey(), 'name' => 'Alpha']);
        $this->actingAs($this->memberOf($account), 'web');

        $tagId = $this->postJson('/api/v1/tags', ['name' => '  Prioridade  ', 'color' => 'warning'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Prioridade')
            ->json('data.id');

        $this->actingAs($this->memberOf($other), 'web')
            ->getJson('/api/v1/tags')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->memberOf($other), 'web')
            ->patchJson('/api/v1/tags/'.$tagId, ['name' => 'Roubada'])
            ->assertNotFound();

        $this->actingAs($this->memberOf($account), 'web')
            ->postJson('/api/v1/tags', ['name' => 'Prioridade', 'color' => 'primary'])
            ->assertUnprocessable();

        $this->actingAs($this->memberOf($account), 'web')
            ->postJson('/api/v1/clients/tags', [
                'ids' => [$client->getKey(), Client::factory()->create()->getKey()],
                'tag_ids' => [$tagId, 999999],
                'action' => 'attach',
            ])
            ->assertOk()
            ->assertJsonPath('data.clients', 1);

        $this->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonPath('data.0.tags.0.name', 'Prioridade')
            ->assertJsonPath('data.0.tags.0.color', 'warning');
    }

    public function test_reader_cannot_manage_or_assign_tags(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $this->actingAs($this->memberOf($account, 'user'), 'web');

        $this->postJson('/api/v1/tags', ['name' => 'Interna', 'color' => 'neutral'])->assertForbidden();
        $this->postJson('/api/v1/clients/tags', [
            'ids' => [1],
            'tag_ids' => [1],
            'action' => 'attach',
        ])->assertForbidden();
    }

    public function test_global_selection_keeps_exclusions_and_ignores_later_clients(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $kept = Client::factory()->create(['account_id' => $account->getKey(), 'name' => 'Mantida']);
        $removed = Client::factory()->create(['account_id' => $account->getKey(), 'name' => 'Fora']);
        $user = $this->memberOf($account);
        $this->actingAs($user, 'web');

        $selection = $this->postJson('/api/v1/clients/selections')->assertOk()->json('data.id');
        $late = Client::factory()->create(['account_id' => $account->getKey(), 'name' => 'Tardia']);
        $tagId = $this->postJson('/api/v1/tags', ['name' => 'Carteira', 'color' => 'primary'])->json('data.id');

        $this->postJson('/api/v1/clients/tags', [
            'selection_id' => $selection,
            'excluded_ids' => [$removed->getKey()],
            'tag_ids' => [$tagId],
            'action' => 'attach',
        ])->assertOk()->assertJsonPath('data.clients', 1);

        $this->assertDatabaseHas('client_tag', ['client_id' => $kept->getKey(), 'tag_id' => $tagId]);
        $this->assertDatabaseMissing('client_tag', ['client_id' => $removed->getKey(), 'tag_id' => $tagId]);
        $this->assertDatabaseMissing('client_tag', ['client_id' => $late->getKey(), 'tag_id' => $tagId]);

        $this->postJson('/api/v1/clients/tags', [
            'ids' => [$kept->getKey()],
            'tag_ids' => [$tagId],
            'action' => 'detach',
        ])->assertOk();

        $this->assertDatabaseMissing('client_tag', ['client_id' => $kept->getKey(), 'tag_id' => $tagId]);

        $this->deleteJson('/api/v1/tags/'.$tagId)->assertNoContent();
        $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(0, 'data');
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
