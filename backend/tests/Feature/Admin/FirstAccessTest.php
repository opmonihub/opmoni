<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_create_with_password_now_creates_owner_user(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/admin/accounts', [
                'name' => 'Escritório Novo',
                'owner_name' => 'Maria Admin',
                'login_email' => 'maria@escritorio.dev',
                'access' => 'password_now',
                'password' => 'senha-segura',
            ])
            ->assertCreated()
            ->assertJsonPath('first_access', null);

        $account = Account::firstWhere('name', 'Escritório Novo');
        $this->assertNotNull($account);
        $user = User::firstWhere('email', 'maria@escritorio.dev');
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->accountRole($account));
    }

    public function test_first_access_link_allows_password_setup_and_login(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $create = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/admin/accounts', [
                'name' => 'Escritório Link',
                'owner_name' => 'João',
                'login_email' => 'joao@escritorio.dev',
                'access' => 'first_access',
            ])
            ->assertCreated();

        $url = $create->json('first_access.url');
        $this->assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token'] ?? null);

        $this->getJson('/api/first-access/status?'.http_build_query([
            'token' => $query['token'],
            'email' => 'joao@escritorio.dev',
        ]))
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('account_name', 'Escritório Link');

        $this->postJson('/api/first-access', [
            'token' => $query['token'],
            'email' => 'joao@escritorio.dev',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'joao@escritorio.dev');

        $this->assertSame(1, User::where('email', 'joao@escritorio.dev')->count());
    }

    public function test_admin_update_persists_billing_contact(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $account = Account::factory()->create(['name' => 'Antes']);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/admin/accounts/{$account->getKey()}", [
                'name' => 'Depois',
                'phone' => '11988887777',
                'phone_whatsapp' => true,
                'state' => 'RJ',
                'city' => 'Rio de Janeiro',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Depois');

        $account->refresh();
        $this->assertSame('Depois', $account->name);
        $this->assertSame([
            'phone' => '11988887777',
            'phone_whatsapp' => true,
            'state' => 'RJ',
            'city' => 'Rio de Janeiro',
        ], $account->settings['platform']['billing_contact']);
    }
}
