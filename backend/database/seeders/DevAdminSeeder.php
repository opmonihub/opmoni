<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Os logins de desenvolvimento, ambos com a senha pública `password`:
 *
 * - `admin@example.com`: Membro `admin` da primeira Account, sem
 *   `is_super_admin`;
 * - `super_admin@example.com`: `is_super_admin`, com `current_account_id`
 *   apontando para a mesma Account de desenvolvimento — é por ele que se
 *   grava o e-CNPJ e o flag do SERPRO na Account.
 *
 * Idempotente: a segunda execução só reafirma senha, flags e vínculo.
 *
 * Roda apenas em `local`: a senha é pública e não pode chegar a outro
 * ambiente por um `db:seed` distraído.
 */
class DevAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@example.com';

    public const SUPER_ADMIN_EMAIL = 'super_admin@example.com';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $account = Account::query()->orderBy('id')->first()
            ?? Account::query()->forceCreate(['name' => 'Escritório Dev', 'status' => 'active']);

        $user = User::query()->firstOrNew(['email' => self::EMAIL]);
        $user->forceFill([
            'name' => 'Admin Dev',
            'password' => self::PASSWORD,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'current_account_id' => $account->getKey(),
            'is_super_admin' => false,
        ])->save();

        AccountUser::query()->updateOrCreate(
            ['account_id' => $account->getKey(), 'user_id' => $user->getKey()],
            ['role' => 'admin'],
        );

        $superAdmin = User::query()->firstOrNew(['email' => self::SUPER_ADMIN_EMAIL]);
        $superAdmin->forceFill([
            'name' => 'Super Admin Dev',
            'password' => self::PASSWORD,
            'email_verified_at' => $superAdmin->email_verified_at ?? now(),
            'current_account_id' => $account->getKey(),
            'is_super_admin' => true,
        ])->save();
    }
}
