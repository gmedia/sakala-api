<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\UpdateProjectData;
use App\Models\Project;

final class UpdateProjectAction
{
    public function handle(Project $project, UpdateProjectData $data): Project
    {
        $project->update($data->toDatabaseArray());

        return $project->fresh();
    }
}
