<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'name', 'q', 'filters'])]
class ClientSavedFilter extends Model
{
    use BelongsToAccount;

    protected static function booted(): void
    {
        static::addGlobalScope('owner', function (Builder $query): void {
            $userId = self::actingUserId();

            if ($userId === null) {
                return;
            }

            $query->where($query->getModel()->getTable().'.user_id', $userId);
        });

        static::creating(function (ClientSavedFilter $filter): void {
            $filter->user_id ??= self::actingUserId();
        });
    }

    /**
     * Resolve the acting user for the `owner` scope without touching the
     * request helper, so jobs and console commands filter `user_id`
     * explicitly instead of inheriting a stale HTTP user.
     */
    private static function actingUserId(): ?int
    {
        $user = auth()->user();

        if ($user === null && app()->runningInConsole()) {
            return null;
        }

        return $user?->getKey();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
