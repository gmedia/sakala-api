<?php

declare(strict_types=1);

namespace App\Data\Database;

/** What one run of the storage quota sweep asked for. */
final class DatabaseStorageSweepData
{
    public function __construct(
        /** Workspaces told they passed the warning share. */
        public int $warned = 0,
        /** LockDatabase commands created. */
        public int $lockRequested = 0,
        /** UnlockDatabase commands created. */
        public int $unlockRequested = 0,
    ) {}
}
