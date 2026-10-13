<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

/**
 * A procuração manual saiu por inteiro: as rotas não existem mais, e a
 * resposta é o 404/405 de quem pediu um endereço que o sistema não serve —
 * nunca um endpoint que finge continuar vivo.
 */
class EcacPowerOfAttorneyRemovedTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_put_e_delete_da_procuracao_manual_nao_existem_mais(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $member = $this->memberOf($account, 'admin');

        $this->actingAs($member, 'web')
            ->putJson("/api/v1/clients/{$client->getKey()}/ecac-power-of-attorney", [
                'starts_at' => '2026-01-01',
                'expires_at' => '2027-01-01',
            ])
            ->assertNotFound();

        $this->actingAs($member, 'web')
            ->deleteJson("/api/v1/clients/{$client->getKey()}/ecac-power-of-attorney")
            ->assertNotFound();
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
