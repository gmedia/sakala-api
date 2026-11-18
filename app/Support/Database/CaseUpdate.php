<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Writes different values to many rows of one table in a single UPDATE, one
 * `CASE` per column, so a heartbeat costs one write whatever the length of its
 * report. PostgreSQL types a bound parameter in a CASE as text, which a
 * timestamptz or integer column refuses; `$pgsqlTypes` names the cast per
 * column there.
 */
final class CaseUpdate
{
    /**
     * @param  array<string, array<string, int|string|null>>  $rowsById  Row ID => column => value. Every row sets the same columns.
     * @param  array<string, string>  $pgsqlTypes  Column => PostgreSQL type of its value.
     */
    public static function apply(string $table, array $rowsById, array $pgsqlTypes = []): int
    {
        if ($rowsById === []) {
            return 0;
        }

        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $pgsql = $connection->getDriverName() === 'pgsql';
        $ids = array_map('strval', array_keys($rowsById));
        $columns = array_keys(reset($rowsById));
        $assignments = [];
        $bindings = [];

        foreach ($columns as $column) {
            $value = $pgsql && isset($pgsqlTypes[$column]) ? "CAST(? AS {$pgsqlTypes[$column]})" : '?';
            $cases = [];

            foreach ($rowsById as $id => $row) {
                $cases[] = "WHEN ? THEN {$value}";
                $bindings[] = (string) $id;
                $bindings[] = $row[$column];
            }

            $assignments[] = sprintf('%s = case %s %s end', $grammar->wrap($column), $grammar->wrap('id'), implode(' ', $cases));
        }

        return $connection->update(
            sprintf(
                'update %s set %s where %s in (%s)',
                $grammar->wrapTable($table),
                implode(', ', $assignments),
                $grammar->wrap('id'),
                $grammar->parameterize($ids),
            ),
            [...$bindings, ...$ids],
        );
    }
}
