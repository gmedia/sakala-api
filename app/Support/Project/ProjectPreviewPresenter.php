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
}
