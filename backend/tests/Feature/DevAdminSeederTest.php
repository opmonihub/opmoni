<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Database\Seeders\DevAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_em_local_cria_o_admin_da_primeira_account_e_repetir_nao_duplica(): void
    {
        $this->app['env'] = 'local';
        $account = Account::factory()->create();

        $this->seed(DevAdminSeeder::class);
        $this->seed(DevAdminSeeder::class);

        $user = User::query()->where('email', DevAdminSeeder::EMAIL)->sole();
        $this->assertTrue(Hash::check(DevAdminSeeder::PASSWORD, $user->password));
        $this->assertSame($account->getKey(), $user->current_account_id);
        $this->assertSame('admin', AccountUser::query()->where('user_id', $user->getKey())->sole()->role);
    }

    public function test_fora_de_local_nao_cria_nada(): void
    {
        $this->app['env'] = 'production';
        Account::factory()->create();

        // `db:seed` em produção pede confirmação; o que se testa é a guarda do próprio seeder.
        (new DevAdminSeeder)->run();

        $this->assertFalse(User::query()->where('email', DevAdminSeeder::EMAIL)->exists());
    }
}
