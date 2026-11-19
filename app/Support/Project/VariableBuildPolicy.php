<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Data\Agent\JavaScriptWorkspaceData;
use App\Enums\VariableBuildReason;
use App\Models\Project;

/**
 * The one place that decides which project variable changes make the next
 * deployment build (see docs/API_CONVENTIONS.md#redeploy-tanpa-build):
 *
 * 1. a static site (files, no server process) reads every variable at build;
 * 2. a framework that can render at build may bake any variable in;
 * 3. a plain server reads variables at runtime, except BuildTimeVariable's
 *    prefixes and keys;
 * 4. an attached database's connection variables are never build-affecting,
 *    for every stack: the database host only admits the runtime networks, so a
 *    build cannot read through them. They are not project variables, so
 *    nothing here sees them; a project variable of the same name follows 1-3/5;
 * 5. without a usable inspection, or for a stack this cannot classify, every
 *    variable counts (fail safe: build).
 *
 * Classified from the project's stored inspection only (Railpack v0.23.0
 * signals), with the guard and labels of ProjectPreviewPresenter. Railpack
 * reports Gatsby and Astro in server mode as plain `node`, so they fall under
 * rule 3 until the agent reports framework dependencies; "Build ulang dari
 * awal" (`force_build`) stays the override.
 */
final class VariableBuildPolicy
{
    /** Railpack's provider for JavaScript projects. */
    private const NODE_PROVIDER = 'node';

    /** Railpack's provider for a folder of files served by Caddy. */
    private const STATIC_PROVIDER = 'staticfile';

    /** `nodeRuntime` of a JavaScript project Railpack serves as files. */
    private const STATIC_NODE_RUNTIME = 'static';

    /** How Railpack writes a true metadata value: always a string, `"true"`. */
    private const METADATA_TRUE = 'true';

    /** Railpack metadata keys read here. */
    private const METADATA_NODE_RUNTIME = 'nodeRuntime';

    private const METADATA_NODE_SPA_FRAMEWORK = 'nodeSPAFramework';

    private const METADATA_NODE_IS_SPA = 'nodeIsSPA';

    /**
     * `nodeRuntime` or `nodeSPAFramework` values of frameworks that can render
     * at build. `vite` without an SPA covers SvelteKit, which Railpack reports
     * as vite, and `vite preview`.
     *
     * @var list<string>
     */
    private const PRERENDER_RUNTIMES = ['next', 'nuxt', 'remix', 'react-router', 'tanstack-start', 'astro', 'vite'];

    /**
     * `nodeRuntime` values of a plain JavaScript server. No `nodeRuntime` at
     * all counts as one too.
     *
     * @var list<string>
     */
    private const PLAIN_NODE_RUNTIMES = ['node', 'bun'];

    /**
     * Railpack providers that always start a server process.
     *
     * @var list<string>
     */
    private const SERVER_PROVIDERS = ['php', 'golang', 'java', 'rust', 'ruby', 'elixir', 'python', 'deno', 'dotnet', 'gleam', 'cpp'];

    /**
     * Why every project variable is build-time for this project, or null for a
     * plain server, where only BuildTimeVariable's prefixes and keys are. Never
     * VariableBuildReason::BuildTimePrefix.
     */
    public static function reasonFor(Project $project): ?VariableBuildReason
    {
        $inspection = ProjectPreviewPresenter::usableInspection($project);

        if ($inspection === null) {
            return VariableBuildReason::UnknownStack;
        }

        // Sakala never passes variables as build args: a Dockerfile only sees
        // one through an explicit secret mount, so it counts as a server.
        if (ProjectPreviewPresenter::usesDockerfile($inspection)) {
            return null;
        }

        $provider = ProjectPreviewPresenter::railpackProvider($inspection);
        $metadata = ProjectPreviewPresenter::railpackMetadata($inspection);
        $runtime = ProjectPreviewPresenter::label($metadata[self::METADATA_NODE_RUNTIME] ?? null);
        $spaFramework = ProjectPreviewPresenter::label($metadata[self::METADATA_NODE_SPA_FRAMEWORK] ?? null);

        // Railpack does not serve an SPA as files when a start command is set.
        if (! self::hasStartCommand($project) && self::servedAsFiles($inspection, $metadata, $provider, $runtime)) {
            return VariableBuildReason::StaticSite;
        }

        if ($provider === self::NODE_PROVIDER
            && (in_array($runtime, self::PRERENDER_RUNTIMES, true) || in_array($spaFramework, self::PRERENDER_RUNTIMES, true))) {
            return VariableBuildReason::PrerenderFramework;
        }

        if (($provider === self::NODE_PROVIDER && ($runtime === null || in_array($runtime, self::PLAIN_NODE_RUNTIMES, true)))
            || in_array($provider, self::SERVER_PROVIDERS, true)) {
            return null;
        }

        return VariableBuildReason::UnknownStack;
    }

    /** True when any change to a project variable makes the next deployment build. */
    public static function everyVariableIsBuildTime(Project $project): bool
    {
        return self::reasonFor($project) !== null;
    }

    /**
     * What `ProjectResource.variable_changes` shows: whether any variable
     * change builds and why, plus the plain-server list, so the Console can
     * tell whether one key is build-time (case-sensitive prefix match).
     *
     * @return array{requires_build: bool, reason: VariableBuildReason|null, build_time_prefixes: list<string>, build_time_keys: list<string>}
     */
    public static function present(Project $project): array
    {
        $reason = self::reasonFor($project);

        return [
            'requires_build' => $reason !== null,
            'reason' => $reason,
            'build_time_prefixes' => BuildTimeVariable::PREFIXES,
            'build_time_keys' => BuildTimeVariable::KEYS,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $inspection
     * @param  array<array-key, mixed>  $metadata
     */
    private static function servedAsFiles(array $inspection, array $metadata, ?string $provider, ?string $runtime): bool
    {
        return self::metadataIsTrue($metadata[self::METADATA_NODE_IS_SPA] ?? null)
            || $provider === self::STATIC_PROVIDER
            || $runtime === self::STATIC_NODE_RUNTIME
            // The agent serves a Vite package of a JavaScript workspace as files.
            || JavaScriptWorkspaceData::fromArray($inspection['workspace'] ?? null)?->staticOutputDirectory !== null;
    }

    /** Railpack writes `"true"`; a bool is accepted too. */
    private static function metadataIsTrue(mixed $value): bool
    {
        return $value === true || ProjectPreviewPresenter::label($value) === self::METADATA_TRUE;
    }

    private static function hasStartCommand(Project $project): bool
    {
        return $project->start_command !== null && $project->start_command !== '';
    }
}
