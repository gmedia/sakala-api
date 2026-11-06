<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Enums\ProjectInspectionStatus;
use App\Models\Project;

/**
 * Console-facing view of a project's inspection. Only the stable fields of
 * the protocol v4 ProjectInspection are exposed; the raw railpack output
 * stays server-side.
 */
final class ProjectPreviewPresenter
{
    /** @var list<string> */
    private const PLAIN_NODE_RUNTIMES = ['node', 'bun', 'deno'];

    public static function status(Project $project): string
    {
        return ($project->inspection_status ?? ProjectInspectionStatus::Unavailable)->value;
    }

    /**
     * @return array{repository_url: string|null, commit_sha: string|null, dockerfile_found: bool, env_example_found: bool, compose_found: bool, manifests: list<string>, package_manager: string|null, inspected_at: string|null}|null
     */
    public static function inspection(Project $project): ?array
    {
        $inspection = $project->inspection;

        if ($inspection === null || $project->inspection_status !== ProjectInspectionStatus::Succeeded) {
            return null;
        }

        $manifests = $inspection['manifests'] ?? [];

        return [
            'repository_url' => is_string($inspection['repository_url'] ?? null) ? $inspection['repository_url'] : null,
            'commit_sha' => is_string($inspection['commit_sha'] ?? null) ? $inspection['commit_sha'] : null,
            'dockerfile_found' => ($inspection['dockerfile_found'] ?? false) === true,
            'env_example_found' => ($inspection['env_example_found'] ?? false) === true,
            'compose_found' => ($inspection['compose_found'] ?? false) === true,
            'manifests' => is_array($manifests) ? array_values(array_filter($manifests, 'is_string')) : [],
            'package_manager' => is_string($inspection['package_manager'] ?? null) ? $inspection['package_manager'] : null,
            'inspected_at' => $project->inspected_at?->toAtomString(),
        ];
    }

    /**
     * What the console may show as the detected stack. `null` means unknown,
     * never a guess. Everything but `package_manager` is derived here from the
     * inspection the agent stored; the raw railpack output stays server-side.
     *
     * The whole summary is null when the inspection looked at another directory
     * than the project uses now: it describes something else until re-inspected.
     *
     * @return array{builder: 'dockerfile'|'railpack', provider: string|null, framework: string|null, package_manager: string|null}|null
     */
    public static function stack(Project $project): ?array
    {
        $inspection = $project->inspection;

        if ($inspection === null
            || $project->inspection_status !== ProjectInspectionStatus::Succeeded
            || ($inspection['root_directory'] ?? null) !== $project->root_directory) {
            return null;
        }

        $railpack = is_array($inspection['railpack'] ?? null) ? $inspection['railpack'] : [];
        $providers = is_array($railpack['detectedProviders'] ?? null) ? $railpack['detectedProviders'] : [];
        $metadata = is_array($railpack['metadata'] ?? null) ? $railpack['metadata'] : [];

        return [
            // The choice auto mode makes: a Dockerfile wins, otherwise railpack.
            'builder' => ($inspection['dockerfile_found'] ?? false) === true ? 'dockerfile' : 'railpack',
            'provider' => self::label($providers[0] ?? null),
            'framework' => self::framework($metadata),
            // The agent's value comes from a lockfile; railpack also decides without one.
            'package_manager' => is_string($inspection['package_manager'] ?? null)
                ? $inspection['package_manager']
                : self::label($metadata['nodePackageManager'] ?? null),
        ];
    }

    /**
     * Read from railpack's `nodeSPAFramework`, then `nodeRuntime`. Its plain runtime
     * values name no framework, and anything else stays null.
     *
     * @param  array<array-key, mixed>  $metadata
     */
    private static function framework(array $metadata): ?string
    {
        $spa = self::label($metadata['nodeSPAFramework'] ?? null);

        if ($spa !== null) {
            return $spa;
        }

        $runtime = self::label($metadata['nodeRuntime'] ?? null);

        return in_array($runtime, self::PLAIN_NODE_RUNTIMES, true) ? null : $runtime;
    }

    /** Agent output is untrusted: only a short plain token may reach the console. */
    private static function label(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $value) !== 1) {
            return null;
        }

        return strtolower($value);
    }
}
