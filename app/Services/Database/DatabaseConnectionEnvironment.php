<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Data\Database\DatabaseCredentialsData;
use App\Data\Database\DatabaseEndpointData;
use App\Exceptions\Database\DatabaseEndpointUnavailableException;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Models\ManagedDatabase;
use App\Models\Project;

/**
 * The connection variables a project receives from its attached database
 * (ADR-020): one URL for libraries that read `DATABASE_URL`, and the split
 * `DB_*` values Laravel reads. They are built when a deployment is created and
 * never stored as the project's own variables, and a variable the project sets
 * itself with the same name wins.
 */
final class DatabaseConnectionEnvironment
{
    public const DATABASE_URL = 'DATABASE_URL';

    public const DB_CONNECTION = 'DB_CONNECTION';

    public const DB_HOST = 'DB_HOST';

    public const DB_PORT = 'DB_PORT';

    public const DB_DATABASE = 'DB_DATABASE';

    public const DB_USERNAME = 'DB_USERNAME';

    public const DB_PASSWORD = 'DB_PASSWORD';

    /** `DB_CONNECTION`: Laravel's PostgreSQL driver name. */
    public const CONNECTION_DRIVER = 'pgsql';

    public const URL_SCHEME = 'postgresql';

    /** The instance only accepts TLS (`hostssl` in pg_hba), so clients must ask for it. */
    public const SSL_MODE = 'require';

    /** @return list<string> Every variable name an attached database sets, in a stable order. */
    public static function names(): array
    {
        return [
            self::DATABASE_URL,
            self::DB_CONNECTION,
            self::DB_HOST,
            self::DB_PORT,
            self::DB_DATABASE,
            self::DB_USERNAME,
            self::DB_PASSWORD,
        ];
    }

    /**
     * Connection details of the database. Null host, port, and URL while its
     * node has not reported an endpoint.
     */
    public function credentials(ManagedDatabase $database): DatabaseCredentialsData
    {
        $endpoint = $database->node?->databaseEndpoint();

        return new DatabaseCredentialsData(
            host: $endpoint?->host,
            port: $endpoint?->port,
            database: $database->pg_database,
            username: $database->pg_role,
            password: (string) $database->password,
            url: $endpoint === null ? null : $this->url($database, $endpoint),
            sslMode: self::SSL_MODE,
        );
    }

    /**
     * The variables the project's next deployment receives from its attached
     * database, without those the project sets itself. Empty without an
     * attached database. Every way a deployment is created reads them, so
     * this is also where a deployment is refused while the database cannot
     * take connections.
     *
     * @return array<string, string>
     *
     * @throws DatabaseNotReadyException while the attached database is still being created, or failed to be.
     * @throws DatabaseEndpointUnavailableException when the database node has not reported its endpoint.
     */
    public function forProject(Project $project): array
    {
        $database = $project->databases()->with('node')->first();

        if ($database === null) {
            return [];
        }

        // Only a database created for this project is attached before it is
        // ready; deploying now would start an app whose database is not there.
        if (! $database->status->isProvisioned()) {
            throw new DatabaseNotReadyException($database->status);
        }

        $endpoint = $database->node?->databaseEndpoint();

        if ($endpoint === null) {
            throw new DatabaseEndpointUnavailableException($database);
        }

        $own = $project->environmentVariables()->pluck('key')->all();

        return array_diff_key($this->variables($database, $endpoint), array_flip($own));
    }

    /**
     * Names the project sets itself among the ones its database would set:
     * those keep the project's value.
     *
     * @param  list<string>  $projectKeys
     * @return list<string>
     */
    public function overridden(array $projectKeys): array
    {
        return array_values(array_intersect(self::names(), $projectKeys));
    }

    /** @return array<string, string> */
    private function variables(ManagedDatabase $database, DatabaseEndpointData $endpoint): array
    {
        return [
            self::DATABASE_URL => $this->url($database, $endpoint),
            self::DB_CONNECTION => self::CONNECTION_DRIVER,
            self::DB_HOST => $endpoint->host,
            self::DB_PORT => (string) $endpoint->port,
            self::DB_DATABASE => $database->pg_database,
            self::DB_USERNAME => $database->pg_role,
            self::DB_PASSWORD => (string) $database->password,
        ];
    }

    private function url(ManagedDatabase $database, DatabaseEndpointData $endpoint): string
    {
        return sprintf(
            '%s://%s:%s@%s:%d/%s?sslmode=%s',
            self::URL_SCHEME,
            rawurlencode($database->pg_role),
            rawurlencode((string) $database->password),
            $endpoint->urlHost(),
            $endpoint->port,
            rawurlencode($database->pg_database),
            self::SSL_MODE,
        );
    }
}
