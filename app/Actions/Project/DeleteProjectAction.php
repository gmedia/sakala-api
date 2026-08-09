<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\Project;

final class DeleteProjectAction
{
    public function handle(Project $project): void
    {
        $project->delete();
    }
}
