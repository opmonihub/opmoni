<?php

namespace App\Models\Scopes;

use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('account_id'), CurrentAccount::fromRequest()->account->id);
    }
}
