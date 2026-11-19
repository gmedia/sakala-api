<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

/**
 * Creating a database for a project while no database server can take one
 * right now (`databases.available` is false). The project would wait for its
 * first deployment behind an instance known to be down, so this path refuses
 * instead of queueing; a database created on its own still waits.
 */
final class DatabaseUnavailableException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_UNAVAILABLE';

    public const STATUS = 409;

    public const DESCRIPTION = 'A database for a project (`project_id`) is refused while no database server can take a new database (`databases.available` in `GET app/pilot-limits` is false).';

    public function __construct()
    {
        parent::__construct('Server database sedang tidak tersedia, jadi database untuk proyek ini belum bisa dibuat. Deploy proyek tanpa database dulu, atau coba lagi nanti.');
    }
}
