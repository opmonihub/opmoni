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

    /**
     * Em `local` existem os dois logins, e eles não são o mesmo papel: o
     * `admin@example.com` fica Membro `admin` **sem** `is_super_admin`, e o
     * `super_admin@example.com` fica super_admin apontando para a mesma
     * Account de desenvolvimento — que é onde ele grava o e-CNPJ e o flag do
     * SERPRO.
     */
    public function test_em_local_admin_fica_membro_sem_super_admin_e_super_admin_divide_a_mesma_account(): void
    {
        $this->app['env'] = 'local';
        $account = Account::factory()->create();

        $this->seed(DevAdminSeeder::class);

        $admin = User::query()->where('email', DevAdminSeeder::EMAIL)->sole();

        $this->assertFalse((bool) $admin->is_super_admin, 'O login de Membro não pode ser super_admin.');
        $this->assertSame('admin', $admin->accountRole($account));
        $this->assertSame($account->getKey(), $admin->current_account_id);

        $super = User::query()->where('email', DevAdminSeeder::SUPER_ADMIN_EMAIL)->first();

        $this->assertNotNull($super, 'O seeder local precisa garantir o login super_admin@example.com.');
        $this->assertTrue((bool) $super->is_super_admin);
        $this->assertSame($account->getKey(), $super->current_account_id);
    }
}
