<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Extensions a tenant may ask the database node to install (ADR-020). The
 * first five are trusted by PostgreSQL; `vector` (pgvector) is not, so only
 * the node's superuser installs it. The values are the wire names the agent
 * accepts in InstallDatabaseExtension.
 */
enum DatabaseExtension: string
{
    case PgCrypto = 'pgcrypto';
    case Citext = 'citext';
    case PgTrgm = 'pg_trgm';
    case Unaccent = 'unaccent';
    case Hstore = 'hstore';
    case Vector = 'vector';
}
