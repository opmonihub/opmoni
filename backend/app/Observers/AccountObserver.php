<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\Department;
use App\Models\Plan;

class AccountObserver
{
    /**
     * Handle the Account "created" event.
     *
     * A semeadura roda sem tenant (console, seed, testes): o `account_id`
     * vai explícito e o `firstOrCreate` mantém o seed idempotente diante do
     * `unique(account_id, name)`.
     */
    public function created(Account $account): void
    {
        $plan = Plan::firstOrCreate(
            ['slug' => 'basico'],
            ['name' => 'Básico', 'limits' => ['users' => 5, 'clients' => 50]]
        );

        $account->subscription()->create(['plan_id' => $plan->getKey()]);

        foreach ([
            'Fiscal' => 'success',
            'Pessoal' => 'info',
            'Contábil' => 'primary',
            'Societário' => 'warning',
        ] as $name => $color) {
            // `firstOrCreate` com `account_id` no where não basta: a coluna não
            // é fillable, então o `create` a descartaria e o `creating` do
            // `BelongsToAccount` procuraria tenant ou usuário — que não existem
            // no save que acabou de criar a conta. A escrita é `forceFill` com
            // a conta que o evento carrega, como o resto do sistema faz fora
            // do request.
            $department = Department::withoutGlobalScopes()
                ->where(['account_id' => $account->getKey(), 'name' => $name])
                ->first();

            if ($department === null) {
                (new Department)->forceFill([
                    'account_id' => $account->getKey(),
                    'name' => $name,
                    'color' => $color,
                ])->save();
            }
        }
    }
}
