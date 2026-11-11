<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;

/**
 * Access goes through the workspace that owns the project: any member reads,
 * owners and members change and deploy, viewers never change anything and never
 * read a secret value (revealing one is `update`). Admins keep every power.
 * Everyone else gets the generic 403.
 */
final class ProjectPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        return $this->roleOf($user, $project) !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model, its environment
     * variables included, and read a secret value.
     */
    public function update(User $user, Project $project): bool
    {
        return $this->managesProjects($user, $project);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->managesProjects($user, $project);
    }

    /**
     * Determine whether the user can trigger deployment for the model.
     */
    public function deploy(User $user, Project $project): bool
    {
        if ($project->status === ProjectStatus::Suspended) {
            return false;
        }

        return $this->managesProjects($user, $project);
    }

    /**
     * Determine whether the user can cancel a deployment. Unlike deploying, this
     * stays allowed on a suspended project: it only ever stops work.
     */
    public function cancelDeployment(User $user, Project $project): bool
    {
        return $this->managesProjects($user, $project);
    }

    /**
     * Determine whether the user can stop the project.
     */
    public function stop(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can suspend the project.
     */
    public function suspend(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can lift a suspension. Admin-only via before().
     */
    public function unsuspend(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can request workload reconciliation.
     * Admin-only via before().
     */
    public function reconcile(User $user, Project $project): bool
    {
        return false;
    }

    private function managesProjects(User $user, Project $project): bool
    {
        return $this->roleOf($user, $project)?->managesProjects() === true;
    }

    private function roleOf(User $user, Project $project): ?WorkspaceRole
    {
        return $project->workspace_id === null ? null : $project->workspace?->roleOf($user);
    }
}
