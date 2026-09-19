<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the multi-session PostgreSQL concurrency suite in
 * tests/Feature/Concurrency. These tests need committed rows visible from a
 * second database session, which the RefreshDatabase transaction cannot
 * provide, so the suite runs without it (group `concurrency`, excluded from
 * the default run) against a schema recreated before each test.
 */
function concurrencyRequiresPostgres(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped(
            'Requires PostgreSQL row-level locking; SQLite does not support FOR UPDATE.',
        );
    }
}

/**
 * Run $holdLocks inside transaction A on the default connection, then run
 * $contend on a second PostgreSQL session with a short lock_timeout while A
 * is still open. $contend must block on a row A holds: this asserts it
 * fails with SQLSTATE 55P03 (lock_not_available). Transaction A is then
 * committed so the caller can verify what the contender observes afterwards.
 */
function whileTransactionHoldsLocks(Closure $holdLocks, Closure $contend): void
{
    $secondary = 'pgsql_secondary';

    config([
        "database.connections.{$secondary}" => config(
            'database.connections.'.DB::getDefaultConnection(),
        ),
    ]);

    DB::purge($secondary);

    $defaultConnection = DB::getDefaultConnection();

    DB::beginTransaction();

    try {
        $holdLocks();

        $secondaryConnection = DB::connection($secondary);
        $secondaryConnection->statement('SET lock_timeout = 250');

        $blocked = null;

        try {
            DB::setDefaultConnection($secondary);

            $contend();
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('55P03');

            $blocked = $e;
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }

        expect($blocked)
            ->toBeInstanceOf(QueryException::class)
            ->and($secondaryConnection->transactionLevel())
            ->toBe(0);

        DB::commit();
    } catch (Throwable $e) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $e;
    } finally {
        DB::purge($secondary);
    }
}
