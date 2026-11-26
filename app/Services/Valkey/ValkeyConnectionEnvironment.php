<?php

declare(strict_types=1);

namespace App\Services\Valkey;

use App\Data\Valkey\ValkeyCredentialsData;
use App\Data\Valkey\ValkeyDeploymentData;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Exceptions\Valkey\ValkeyPasswordRotatingException;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Support\Runtime\RuntimeFeatures;
use App\Support\Valkey\ValkeyPasswordWindow;

/**
 * The variables and network a project receives from its attached Valkey
 * store (ADR-023). They are built when a deployment is created and never
 * stored as the project's own variables, and a variable the project sets
 * itself with the same name wins; the network is joined either way.
 *
 * `REDIS_URL` carries no database number: Laravel applies the URL's number
 * to both its default and its cache connection, so `cache:clear` would
 * flush queued jobs. `REDIS_USERNAME` (tenants use `default`), `REDIS_DB`,
 * `REDIS_CLIENT`, `QUEUE_CONNECTION`, `CACHE_STORE`, and `SESSION_DRIVER`
 * are never injected: they change how the application behaves beyond the
 * connection, so the Console suggests them instead.
 */
final class ValkeyConnectionEnvironment
{
    public const REDIS_URL = 'REDIS_URL';

    public const REDIS_HOST = 'REDIS_HOST';

    public const REDIS_PORT = 'REDIS_PORT';

    public const REDIS_PASSWORD = 'REDIS_PASSWORD';

    /** URL scheme; no TLS in v1, the traffic never leaves a bridge on one node. */
    public const URL_SCHEME = 'redis';

    /** @return list<string> Every variable name an attached store sets, in a stable order. */
    public static function names(): array
    {
        return [self::REDIS_URL, self::REDIS_HOST, self::REDIS_PORT, self::REDIS_PASSWORD];
    }

    /**
     * `redis://:<password>@<host>:<port>`, with no database path. The password
     * is letters and digits only, so it needs no escaping.
     */
    public static function url(string $host, int $port, string $password): string
    {
        return sprintf('%s://:%s@%s:%d', self::URL_SCHEME, $password, $host, $port);
    }

    /**
     * The store's connection details with its password, or null while it has
     * no address yet.
     */
    public function credentials(ManagedValkey $valkey): ?ValkeyCredentialsData
    {
        if ($valkey->host === null || $valkey->port === null) {
            return null;
        }

        $password = (string) $valkey->password;

        return new ValkeyCredentialsData(
            host: $valkey->host,
            port: $valkey->port,
            password: $password,
            url: self::url($valkey->host, $valkey->port, $password),
            variables: $this->variables($valkey->host, $valkey->port, $password),
        );
    }

    /**
     * What the project's next deployment gets from its attached store: the
     * variables it does not set itself, the `valkey` payload field, and the
     * store's node. Null without an attached store, and while
     * SAKALA_VALKEY_ENABLED is off. Every way a deployment is created reads
     * this, so this is also where a deployment is refused while the store
     * cannot be used. A locked store still injects: the app gets an auth
     * error, as with a locked database.
     *
     * @throws ValkeyNotReadyException while the attached store is still being created, failed, or is being deleted.
     * @throws ValkeyPasswordRotatingException while nobody knows which password works (ValkeyPasswordWindow).
     */
    public function forProject(Project $project): ?ValkeyDeploymentData
    {
        if (! RuntimeFeatures::valkeyEnabled()) {
            return null;
        }

        $valkey = $project->valkeys()->first();

        if ($valkey === null) {
            return null;
        }

        // Only a store created for this project is attached before it is
        // ready; deploying now would start an app whose store is not there.
        if (! $valkey->status->isProvisioned() || $valkey->network === null || $valkey->host === null || $valkey->port === null) {
            throw new ValkeyNotReadyException($valkey->status);
        }

        if (ValkeyPasswordWindow::isOpen($valkey)) {
            throw new ValkeyPasswordRotatingException($valkey);
        }

        $own = $project->environmentVariables()->pluck('key')->all();

        return new ValkeyDeploymentData(
            environment: array_diff_key($this->variables($valkey->host, $valkey->port, (string) $valkey->password), array_flip($own)),
            attachment: ['network' => $valkey->network, 'host' => $valkey->host],
            nodeId: $valkey->node_id,
            valkey: $valkey,
        );
    }

    /**
     * Names the project sets itself among the ones its store would set: those
     * keep the project's value.
     *
     * @param  list<string>  $projectKeys
     * @return list<string>
     */
    public function overridden(array $projectKeys): array
    {
        return array_values(array_intersect(self::names(), $projectKeys));
    }

    /** @return array<string, string> In the order of names(). */
    private function variables(string $host, int $port, string $password): array
    {
        return [
            self::REDIS_URL => self::url($host, $port, $password),
            self::REDIS_HOST => $host,
            self::REDIS_PORT => (string) $port,
            self::REDIS_PASSWORD => $password,
        ];
    }
}
