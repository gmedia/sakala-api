<?php

declare(strict_types=1);

namespace App\Enums;

/** Engines a managed database can run. ADR-020 starts, and for now ends, with PostgreSQL. */
enum DatabaseEngine: string
{
    case PostgreSql = 'postgresql';
}
