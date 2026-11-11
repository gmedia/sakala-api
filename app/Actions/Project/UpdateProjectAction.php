<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\UpdateProjectData;
use App\Models\Project;

final class UpdateProjectAction
{
    public function handle(Project $project, UpdateProjectData $data): Project
    {
        $attributes = $data->toDatabaseArray();

        $project->fill($attributes);

        // The detected port belongs to the directory that was inspected.
        if ($project->isDirty('root_directory')) {
            $project->detected_port = null;
        }

        // Only a real change to what the next deployment receives needs a redeploy.
        if ($project->isDirty(array_keys($data->buildConfiguration))) {
            $project->config_changed_at = now()->toImmutable();
        }

        $project->save();

        return $project->fresh();
    }
}
