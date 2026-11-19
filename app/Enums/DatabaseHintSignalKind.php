<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an inspection found that suggests the project uses a database. The
 * agent may add kinds later; the API keeps only the ones it knows.
 */
enum DatabaseHintSignalKind: string
{
    /** A key such as `DATABASE_URL` or `DB_HOST` in the example environment; `name` is the key. */
    case EnvVariable = 'env_variable';

    /** A Laravel app with at least one migration in `database/migrations`. */
    case LaravelMigrations = 'laravel_migrations';

    /** A Prisma schema with a `datasource`; `name` is its provider. */
    case PrismaSchema = 'prisma_schema';

    /** Django settings that mention `DATABASES` or `dj_database_url`. */
    case DjangoSettings = 'django_settings';

    /** `config/database.yml`; `name` is its first adapter. */
    case RailsDatabaseConfig = 'rails_database_config';

    /** A database driver or ORM among the project's dependencies; `name` is the package. */
    case PackageDependency = 'package_dependency';
}
