<?php

namespace App\Models\Concerns;

use App\Models\Scopes\AccountScope;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Model;

trait BelongsToAccount
{
    protected static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);
        static::creating(function (Model $model): void {
            $context = CurrentAccount::fromRequest();
            $context->assertWrite();
            abort_if($model->getAttribute('account_id') !== null && (int) $model->getAttribute('account_id') !== $context->account->id, 422, 'Escritório inválido');
            $model->setAttribute('account_id', $context->account->id);
        });
        static::updating(function (Model $model): void {
            $context = CurrentAccount::fromRequest();
            $context->assertWrite();
            abort_unless((int) $model->getOriginal('account_id') === $context->account->id && (int) $model->getAttribute('account_id') === $context->account->id, 422, 'Escritório inválido');
        });
        static::deleting(function (Model $model): void {
            $context = CurrentAccount::fromRequest();
            $context->assertWrite();
            abort_unless((int) $model->getAttribute('account_id') === $context->account->id, 404);
        });
    }
}
