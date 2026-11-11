<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Case-insensitive substring search over a few columns: `ILIKE` on
 * PostgreSQL, `LIKE` on SQLite. `%` and `_` typed by an admin act as
 * wildcards, which only widens an admin's own search.
 */
final class AdminSearch
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, array $columns, ?string $term): Builder
    {
        if ($term === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($columns, $term): void {
            foreach ($columns as $column) {
                $query->orWhereLike($column, "%{$term}%");
            }
        });
    }
}
