<?php

declare(strict_types=1);

namespace App\Support\Workspace;

use App\Models\Project;

/**
 * The owning workspace as it appears on project resources. The caller's role is
 * not here: it comes from GET /api/v1/app/workspaces.
 */
final class WorkspaceSummaryPresenter
{
    /** @return array{id: string, name: string, slug: string, type: string}|null */
    public static function forProject(Project $project): ?array
    {
        $workspace = $project->workspace_id === null ? null : $project->loadMissing('workspace')->workspace;

        if ($workspace === null) {
            return null;
        }

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'type' => $workspace->type->value,
        ];
    }
}
