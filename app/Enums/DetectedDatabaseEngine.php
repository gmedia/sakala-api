<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The database engine an inspection guessed the project expects, from its
 * example environment, schema files, or dependencies. Only a hint: managed
 * databases are PostgreSQL (DatabaseEngine).
 */
enum DetectedDatabaseEngine: string
{
    case PostgreSql = 'postgresql';

    case MySql = 'mysql';

    case Sqlite = 'sqlite';

    /** Nothing decisive was found, or the agent sent a value outside this list. */
    case Unknown = 'unknown';
}
