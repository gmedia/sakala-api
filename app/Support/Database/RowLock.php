<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Row lock clauses beyond Laravel's `lockForUpdate()` (FOR UPDATE) and
 * `sharedLock()` (FOR SHARE), for `Builder::lock()`. SQLite has no row locks
 * and compiles every clause to nothing.
 */
final class RowLock
{
    /**
     * For a transaction that changes a row but never its key. It excludes the
     * same writers as FOR UPDATE (other FOR NO KEY UPDATE, FOR UPDATE, FOR
     * SHARE, UPDATE, DELETE), but not the FOR KEY SHARE a foreign key check
     * takes. An insert that references the locked row therefore does not wait
     * for it, so a transaction that holds a referencing parent (a project
     * that creates a command for the node) cannot deadlock with the one
     * holding this row.
     */
    public const NO_KEY_UPDATE = 'for no key update';
}
