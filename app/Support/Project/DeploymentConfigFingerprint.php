<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Models\EnvironmentVariable;
use App\Models\ManagedDatabase;
use App\Models\Project;

/**
 * The one definition of what is deploy-relevant: what the next deployment
 * receives that the serving one may not have. A deployment stores the
 * fingerprint of the project it was created from, and `redeploy_required`
 * compares it with the project's current one, so changing a value and
 * changing it back asks for nothing.
 *
 * Deploy-relevant: the branch, the effective port (what `container_port`
 * gets, so a newly detected port counts while none is configured), the build,
 * start, and release commands, the root directory, the project's `replicas`
 * setting, the environment variables' keys and values, and the attached
 * managed database's credentials. Not: the name, thumbnail, auto-deploy,
 * region, or `is_secret`. Resources are requested per deployment, not stored
 * on the project. The database node's address is not either: it comes from a
 * heartbeat at deploy time, and a node that moves needs a redeploy anyway.
 *
 * Both fingerprints are HMAC-SHA256 keyed with APP_KEY, because environment
 * values are secrets and a bare hash of a short one can be guessed from a
 * database dump. Rotating APP_KEY therefore changes every environment
 * fingerprint and shows one needless redeploy hint per project; that is
 * acceptable, since the environment values need the key anyway.
 *
 * The build fingerprint (forBuild()) is a narrower definition: what changes
 * the image a build produces, so a deployment whose build fingerprint and
 * commit match the live one may reuse its image.
 */
final class DeploymentConfigFingerprint
{
    private const ALGORITHM = 'sha256';

    /** Leads the build fingerprint's input, so it never equals another fingerprint over the same values. */
    private const BUILD_SCOPE = 'build';

    /**
     * Leads it instead when every project variable is build-time
     * (VariableBuildPolicy), so it never equals the plain-server fingerprint
     * over the same values.
     */
    private const BUILD_ALL_VARIABLES_SCOPE = 'build:all-variables';

    /**
     * Pure: reads only the project's own attributes, so a list of projects
     * needs no extra query. `environment_fingerprint` must be current, which
     * the environment variable actions and CreateDeploymentAction ensure.
     */
    public static function forProject(Project $project): string
    {
        return self::sign([
            $project->branch,
            $project->effectivePort(),
            $project->build_command,
            $project->start_command,
            $project->root_directory,
            $project->replicas,
            $project->environment_fingerprint,
            // Appended only when set, so a project without one keeps the
            // fingerprint its deployments stored before the field existed.
            ...($project->hasReleaseCommand() ? [$project->release_command] : []),
            // Same for an attached database: detaching it gives back the
            // fingerprint of before it was attached.
            ...($project->database_fingerprint !== null ? ['database', $project->database_fingerprint] : []),
        ]);
    }

    /**
     * What an attached database hands a deployment: its identity, names, and
     * password, so attaching, detaching, and rotating the password all change
     * it. Null without a database.
     */
    public static function forDatabase(?ManagedDatabase $database): ?string
    {
        if ($database === null) {
            return null;
        }

        return self::sign([$database->id, $database->pg_database, $database->pg_role, (string) $database->password]);
    }

    /**
     * Keys and plaintext values sorted by key, never the ciphertext: it is
     * different on every write, and reverting a value must give the same
     * fingerprint.
     *
     * @param  iterable<EnvironmentVariable>  $variables
     */
    public static function forEnvironment(iterable $variables): string
    {
        $pairs = [];

        foreach ($variables as $variable) {
            $pairs[$variable->key] = [$variable->key, (string) $variable->encrypted_value];
        }

        ksort($pairs, SORT_STRING);

        return self::sign(array_values($pairs));
    }

    /**
     * What decides the image a build produces: the root directory, the build
     * command, the start command (Railpack bakes it into the image), and the
     * build-time variables with their plaintext values, sorted by key. An
     * empty command counts as none, as in the payload. Stored as
     * `deployments.build_fingerprint` and compared by
     * PlanDeploymentBuildAction. Pass the project variables already loaded for
     * the payload, so both see the same values; an attached database's
     * connection variables are not among them, so they never count.
     *
     * Which variables are build-time is VariableBuildPolicy's call: all of
     * them, under their own scope marker, for a static site, a prerendering
     * framework, or an unknown stack; otherwise only BuildTimeVariable's, with
     * exactly the input of v0.9.0, so live plain-server deployments keep
     * matching.
     *
     * @param  iterable<EnvironmentVariable>  $variables
     */
    public static function forBuild(Project $project, iterable $variables): string
    {
        $everyVariable = VariableBuildPolicy::everyVariableIsBuildTime($project);
        $pairs = [];

        foreach ($variables as $variable) {
            if ($everyVariable || BuildTimeVariable::matches($variable->key)) {
                $pairs[$variable->key] = [$variable->key, (string) $variable->encrypted_value];
            }
        }

        ksort($pairs, SORT_STRING);

        return self::sign([
            $everyVariable ? self::BUILD_ALL_VARIABLES_SCOPE : self::BUILD_SCOPE,
            self::presentOrNull($project->root_directory),
            self::presentOrNull($project->build_command),
            self::presentOrNull($project->start_command),
            array_values($pairs),
        ]);
    }

    private static function presentOrNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /** @param  list<mixed>  $canonical */
    private static function sign(array $canonical): string
    {
        return hash_hmac(
            self::ALGORITHM,
            json_encode($canonical, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }
}
