<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\AuditEvent;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;

final class RevealEnvironmentVariableValueAction
{
    /**
     * Records the reveal before the value leaves: if the audit row cannot be
     * written, the caller gets an error and not the secret. A non-secret value is
     * in the variable list already, so reading it here reveals nothing new.
     *
     * The address and user agent are kept only for admins reading a project of a
     * workspace they cannot manage, matching what the privacy notice says about
     * the audit trail. `by_project_owner` means the reader is an owner or member
     * of the project's workspace.
     */
    public function handle(Project $project, EnvironmentVariable $variable, User $user): EnvironmentVariable
    {
        if (! $variable->is_secret) {
            return $variable;
        }

        $readsOthersProject = $project->workspace?->roleOf($user)?->managesProjects() !== true;

        AuditEvent::create([
            'actor_type' => User::class,
            'actor_id' => (string) $user->id,
            'action' => 'environment_variable.value_revealed',
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'ip_address' => $readsOthersProject ? request()->ip() : null,
            'user_agent' => $readsOthersProject ? request()->userAgent() : null,
            'metadata' => [
                'variable_id' => $variable->id,
                'variable_name' => $variable->key,
                'by_project_owner' => ! $readsOthersProject,
            ],
        ]);

        return $variable;
    }
}
