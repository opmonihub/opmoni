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
     * Em `local` há um login só, e ele é os dois papéis: o `admin@example.com`
     * é super_admin **e** Membro `admin` da primeira Account — que é onde ele
     * grava o e-CNPJ e o flag do SERPRO como dono do escritório, sem que a
     * própria conta pareça acesso de suporte.
     */
    public function test_em_local_admin_e_super_admin_e_membro_da_primeira_account(): void
    {
        $this->app['env'] = 'local';
        $account = Account::factory()->create();

        $this->seed(DevAdminSeeder::class);

        $admin = User::query()->where('email', DevAdminSeeder::EMAIL)->sole();

        $this->assertTrue((bool) $admin->is_super_admin, 'O login da conta 1 é o super_admin.');
        $this->assertSame('admin', $admin->accountRole($account));
        $this->assertSame($account->getKey(), $admin->current_account_id);
    }

    public function test_em_local_o_login_super_admin_separado_deixa_de_existir(): void
    {
        $this->app['env'] = 'local';
        Account::factory()->create();

        // Uma base dev antiga ainda tem o segundo login: o seed o apaga, porque
        // a conta 1 é de uma pessoa só.
        User::factory()->create(['email' => DevAdminSeeder::SUPER_ADMIN_EMAIL, 'is_super_admin' => true]);

        $this->seed(DevAdminSeeder::class);

        $this->assertFalse(
            User::query()->where('email', DevAdminSeeder::SUPER_ADMIN_EMAIL)->exists(),
            'O login super_admin@example.com tinha de ser removido pelo seed.',
        );
    }
}
