<?php

declare(strict_types=1);

namespace App\Support\Project;

/**
 * Which project variables change the image a build produces for a plain
 * server (VariableBuildPolicy decides when every variable does instead).
 * Railpack exposes every variable to every build step, but only these end up
 * in a server's output: frameworks inline the public prefixes into the bundle,
 * `RAILPACK_` configures the builder, and `NODE_ENV` switches how JavaScript
 * projects build. Adding, changing, or removing one makes the next deployment
 * build; every other variable, and an attached database's connection
 * variables, is read at runtime only, so a deployment that changes just those
 * may reuse the live image (see PlanDeploymentBuildAction). The "Build ulang
 * dari awal" action (`force_build`) covers a project that bakes another
 * variable in.
 */
final class BuildTimeVariable
{
    /**
     * Key prefixes of build-time variables: Next.js, Nuxt, Vite, Create React
     * App, Vue CLI, Gatsby, Expo, SvelteKit and Astro, and Railpack itself.
     *
     * @var list<string>
     */
    public const PREFIXES = [
        'NEXT_PUBLIC_',
        'NUXT_PUBLIC_',
        'VITE_',
        'REACT_APP_',
        'VUE_APP_',
        'GATSBY_',
        'EXPO_PUBLIC_',
        'PUBLIC_',
        'RAILPACK_',
    ];

    /**
     * Keys that are build-time as a whole.
     *
     * @var list<string>
     */
    public const KEYS = ['NODE_ENV'];

    /** Case-sensitive, like the frameworks that read these keys. */
    public static function matches(string $key): bool
    {
        if (in_array($key, self::KEYS, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
