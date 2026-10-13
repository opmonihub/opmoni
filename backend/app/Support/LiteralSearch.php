<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class LiteralSearch
{
    public static function whereContains(Builder $query, string $column, string $term): Builder
    {
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);
        $escapedTerm = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        return $query->whereRaw("LOWER({$wrappedColumn}) LIKE LOWER(?) ESCAPE '!'", ["%{$escapedTerm}%"]);
    }
}
