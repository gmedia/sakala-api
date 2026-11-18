<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Models\EnvironmentVariable;
use App\Models\Project;

/**
 * The one definition of what is deploy-relevant: what the next deployment
 * receives that the serving one may not have. A deployment stores the
 * fingerprint of the project it was created from, and `redeploy_required`
 * compares it with the project's current one, so changing a value and
 * changing it back asks for nothing.
 *
 * Deploy-relevant: the branch, the effective port (what `container_port`
 * gets, so a newly detected port counts while none is configured), the build
 * and start commands, the root directory, the project's `replicas` setting,
 * and the environment variables' keys and values. Not: the name, thumbnail,
 * auto-deploy, region, or `is_secret`. Resources are requested per
 * deployment, not stored on the project.
 *
 * Both fingerprints are HMAC-SHA256 keyed with APP_KEY, because environment
 * values are secrets and a bare hash of a short one can be guessed from a
 * database dump. Rotating APP_KEY therefore changes every environment
 * fingerprint and shows one needless redeploy hint per project; that is
 * acceptable, since the environment values need the key anyway.
 */
final class DeploymentConfigFingerprint
{
    private const ALGORITHM = 'sha256';

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
        ]);
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
