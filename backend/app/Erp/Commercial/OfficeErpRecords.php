<?php

namespace App\Erp\Commercial;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        abort_unless($record->exists === false, 409);
        self::assertAccountOwnedModel($record);

        $recordAccountId = $record->getAttribute('account_id');
        abort_if($recordAccountId !== null && (int) $recordAccountId !== $ctx->accountId, 404);

        return $ctx->run(function () use ($ctx, $record): Model {
            $record->setAttribute('account_id', $ctx->accountId);
            $record->save();

            return $record;
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
        abort_unless(Schema::hasColumn($model->getTable(), 'account_id'), 422);
    }
}
