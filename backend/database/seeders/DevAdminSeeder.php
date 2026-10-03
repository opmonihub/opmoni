<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * O login de desenvolvimento, com a senha pública `password`:
 *
 * - `admin@example.com`: `is_super_admin` **e** Membro `admin` da primeira
 *   Account — um login só, que é o dono do escritório e quem entrega o
 *   e-CNPJ e liga a integração. `current_account_id` aponta para ela, e o
 *   vínculo `admin` é o que faz a conta própria não acender o banner de
 *   acesso de suporte: ele aparece quando o super_admin entra numa Account da
 *   qual não é Membro.
 *
 * O segundo e-mail que existia, `super_admin@example.com`, deixou de existir:
 * se uma base dev antiga ainda o tiver, a próxima rodada deste seeder o apaga
 * — a conta 1 tem uma pessoa só, e dois logins diriam que são duas.
 *
 * Idempotente: a segunda execução só reafirma senha, flags e vínculo.
 *
 * Roda apenas em `local`: a senha é pública e não pode chegar a outro
 * ambiente por um `db:seed` distraído.
 */
class DevAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@example.com';

    /**
     * O login que a versão anterior deste seeder criava, e que a conta 1 não
     * usa mais: a constante fica para que o seeder saiba o que apagar, e para
     * que o teste afirme a remoção pelo nome.
     */
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
            'is_super_admin' => true,
        ])->save();

        AccountUser::query()->updateOrCreate(
            ['account_id' => $account->getKey(), 'user_id' => $user->getKey()],
            ['role' => 'admin'],
        );

        // O login extra some: na conta 1 quem é super_admin é o próprio admin
        // do escritório, e um `super_admin@` separado descreveria uma pessoa
        // que não existe.
        User::query()->where('email', self::SUPER_ADMIN_EMAIL)->delete();
    }
}
