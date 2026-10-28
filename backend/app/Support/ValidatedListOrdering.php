<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final class ValidatedListOrdering
{
    /**
     * Apply fields from a resource-owned allowlist and add an identifier tie-breaker.
     *
     * @param  array<string, string|Closure(Builder, string): void>  $columns
     */
    public static function apply(Builder $query, ?string $sort, ?string $direction, array $columns, string $idColumn): Builder
    {
        if ($sort === null || ! array_key_exists($sort, $columns)) {
            return $query;
        }

        $direction ??= 'asc';
        $column = $columns[$sort];
        if ($column instanceof Closure) {
            $column($query, $direction);
        } else {
            if ($sort !== 'id') {
                $query->orderByRaw('CASE WHEN '.$column.' IS NULL THEN 1 ELSE 0 END ASC');
            }
            $query->orderBy($column, $direction);
        }

        if ($sort !== 'id') {
            $query->orderBy($idColumn, $direction);
        }

        return $query;
    }
}
