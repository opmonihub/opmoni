<?php

namespace App\Models;

use App\Support\LiteralSearch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_id', 'status'])]
class Subscription extends Model
{
    /** @use HasFactory<Subscription> */
    use HasFactory;

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $subscriptions) use ($term): void {
            $subscriptions
                ->whereHas('account', fn (Builder $accounts): Builder => LiteralSearch::whereContains($accounts, 'name', $term))
                ->orWhereHas('plan', fn (Builder $plans): Builder => LiteralSearch::whereContains($plans, 'name', $term));

            if (ctype_digit($term)) {
                $subscriptions->orWhereKey((int) $term);
            }
        });
    }

    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return $status === null ? $query : $query->where('status', $status);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
