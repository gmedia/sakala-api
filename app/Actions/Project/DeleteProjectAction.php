<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

final class DeleteProjectAction
{
    public function __construct(
        protected DatabaseManager $db,
    ) {}

    /**
     * Handle the deletion of a project.
     */
    public function handle(User $user, Project $project): void
    {
        $this->db->transaction(function () use ($project): void {
            $project->delete();
        });
    }
}
