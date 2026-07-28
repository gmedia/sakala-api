<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\Project;
use App\Models\User;

final class GetProjectAction
{
    /**
     * Handle the retrieval of a project.
     */
    public function handle(User $user, Project $project): Project
    {
        return $project;
    }
}
