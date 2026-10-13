<?php

namespace App\Concerns;

use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @mixin Model */
trait BelongsToAccount
{
    protected static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('account_id'), static::requiredAccountId());
        });

        static::creating(function (Model $model): void {
            $context = request()->attributes->get(CurrentAccount::class);
            if ($context instanceof CurrentAccount) {
                $context->assertWrite();
                abort_if($model->account_id !== null && (int) $model->account_id !== (int) $context->account->id, 422, 'Escritório inválido');
                $model->account_id = $context->account->id;
            } else {
                $model->account_id ??= resolve(CurrentTenant::class)->accountId;
                abort_unless($model->account_id !== null && (int) $model->account_id > 0, 403, 'Escritório não selecionado');
            }
        });

        static::updating(function (Model $model): void {
            $context = request()->attributes->get(CurrentAccount::class);
            if ($context instanceof CurrentAccount) {
                $context->assertWrite();
                abort_unless((int) $model->getOriginal('account_id') === (int) $context->account->id && (int) $model->account_id === (int) $context->account->id, 422, 'Escritório inválido');
            } else {
                abort_unless((int) $model->account_id === (int) $model->getOriginal('account_id'), 422, 'Escritório inválido');
            }
        });

        static::deleting(function (Model $model): void {
            $context = request()->attributes->get(CurrentAccount::class);
            if ($context instanceof CurrentAccount) {
                $context->assertWrite();
                abort_unless((int) $model->account_id === (int) $context->account->id, 404);
            }
        });
    }

    private static function requiredAccountId(): int
    {
        $context = request()->attributes->get(CurrentAccount::class);
        $id = $context instanceof CurrentAccount ? $context->account->id : resolve(CurrentTenant::class)->accountId;
        abort_unless($id !== null && (int) $id > 0, 403, 'Escritório não selecionado');

        return (int) $id;
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($this->qualifyColumn('account_id'), static::requiredAccountId())
            ->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
