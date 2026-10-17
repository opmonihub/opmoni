<?php

namespace App\Observers;

use App\Actions\EnsureAccountSubscription;
use App\Models\Account;
use App\Models\Department;

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
        $this->initialize($account);
    }

    public function initialize(Account $account): void
    {
        app(EnsureAccountSubscription::class)->handle($account);

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
