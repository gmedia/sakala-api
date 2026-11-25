<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * How often `DB::transaction($callback, TransactionRetry::ATTEMPTS)` runs a
 * transaction that PostgreSQL aborted as a deadlock victim. Laravel retries
 * only the outermost transaction and only on a concurrency error, so a
 * nested call or any other failure is unaffected.
 *
 * Use it only where the callback is safe to run again from the start: it
 * writes nothing but rows of its own transaction, and anything outside the
 * database (events, notifications, jobs, HTTP) happens after commit.
 */
final class TransactionRetry
{
    public const ATTEMPTS = 3;
}
