<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * O login de desenvolvimento `admin@example.com` / `password`, admin da
 * primeira Account. Idempotente: a segunda execução só reafirma senha e
 * vínculo.
 *
 * Roda apenas em `local`: a senha é pública e não pode chegar a outro
 * ambiente por um `db:seed` distraído.
 */
class DevAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@example.com';

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
        ])->save();

        AccountUser::query()->updateOrCreate(
            ['account_id' => $account->getKey(), 'user_id' => $user->getKey()],
            ['role' => 'admin'],
        );
    }
}
