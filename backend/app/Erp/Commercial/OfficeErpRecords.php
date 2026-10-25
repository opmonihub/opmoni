<?php

namespace App\Erp\Commercial;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OfficeErpRecords
{
    /** @param class-string<Model> $modelClass */
    public static function query(OfficeErpExecutionContext $ctx, string $modelClass): Builder
    {
        $ctx->assertVerified();
        $model = self::model($modelClass);
        self::assertAccountOwnedModel($model);

        return $ctx->run(fn (): Builder => $modelClass::query()
            ->where($model->qualifyColumn('account_id'), $ctx->accountId));
    }

    public static function persist(OfficeErpExecutionContext $ctx, Model $record): Model
    {
        $ctx->assertVerified();
        self::assertAccountOwnedModel($record);

        $recordAccountId = $record->getAttribute('account_id');
        if ($record->exists) {
            abort_unless(
                (int) $record->getRawOriginal('account_id') === $ctx->accountId
                    && (int) $recordAccountId === $ctx->accountId,
                404,
            );
        } else {
            abort_if($recordAccountId !== null && (int) $recordAccountId !== $ctx->accountId, 404);
        }

        return DB::connection($record->getConnectionName())->transaction(function () use ($ctx, $record): Model {
            return $ctx->run(function () use ($ctx, $record): Model {
                if ($record->exists) {
                    $storedAccountId = DB::connection($record->getConnectionName())
                        ->table($record->getTable())
                        ->where($record->getKeyName(), $record->getKey())
                        ->lockForUpdate()
                        ->value('account_id');
                    abort_unless((int) $storedAccountId === $ctx->accountId, 404);
                } else {
                    $record->setAttribute('account_id', $ctx->accountId);
                }

                $record->save();

                return $record;
            });
        });
    }

    /** @param class-string<Model> $modelClass */
    private static function model(string $modelClass): Model
    {
        abort_unless(class_exists($modelClass) && is_subclass_of($modelClass, Model::class), 422);

        return new $modelClass;
    }

    private static function assertAccountOwnedModel(Model $model): void
    {
        $namespace = 'App\\Models\\Erp\\Commercial\\';
        $class = $model::class;
        abort_unless(str_starts_with($class, $namespace)
            && str_starts_with(substr($class, strlen($namespace)), 'OfficeErp'), 422);
        abort_unless(Schema::hasColumn($model->getTable(), 'account_id'), 422);
    }
}
