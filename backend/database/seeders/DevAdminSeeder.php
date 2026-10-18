<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevAdminSeeder extends Seeder
{
    public const string EMAIL = 'admin@example.com';

    public const string PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $account = Account::where('status', 'active')->orderBy('id')->first()
                ?? Account::create(['name' => 'Opmoni', 'status' => 'active', 'team_size' => 'solo']);

            $admin = User::updateOrCreate(['email' => self::EMAIL], [
                'name' => 'Administrador',
                'password' => self::PASSWORD,
                'is_super_admin' => true,
            ]);

            AccountMembership::updateOrCreate([
                'account_id' => $account->id,
                'user_id' => $admin->id,
            ], ['role' => 'admin']);
        });
    }
}
