<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationCategory;
use App\Models\Project;
use App\Models\User;
use App\Notifications\UserAlertNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers in-app notifications after the surrounding transaction commits, so
 * a rollback never leaves a notification about something that did not happen.
 * A delivery problem is reported, never raised: the agent report or request
 * that caused it has already been committed and must still succeed.
 */
final class UserNotifier
{
    /**
     * A project whose owner deleted the account has nobody left to tell. A
     * mandatory notice ignores the preference: the owner of a suspended project
     * must learn why even with that category switched off.
     */
    public function send(
        ?User $user,
        NotificationCategory $category,
        string $title,
        string $message,
        ?string $projectId = null,
        ?string $deploymentId = null,
        bool $mandatory = false,
        ?string $invitationId = null,
        ?string $workspaceId = null,
    ): void {
        if ($user === null || (! $mandatory && ! $user->wantsNotification($category))) {
            return;
        }

        DB::afterCommit(static function () use ($user, $category, $title, $message, $projectId, $deploymentId, $invitationId, $workspaceId): void {
            try {
                $user->notify(new UserAlertNotification($category, $title, $message, $projectId, $deploymentId, $invitationId, $workspaceId));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Notifies the people who can act on the project: its workspace's owners and
     * members (in a personal workspace, the owner). Viewers only read, so they
     * are left out. With $preferred, only that person is told while they can
     * still act on the project, for news about their own deployment.
     */
    public function sendToProject(
        Project $project,
        NotificationCategory $category,
        string $title,
        string $message,
        ?string $deploymentId = null,
        bool $mandatory = false,
        ?User $preferred = null,
    ): void {
        foreach ($this->projectRecipients($project, $preferred) as $user) {
            $this->send($user, $category, $title, $message, $project->id, $deploymentId, $mandatory);
        }
    }

    /**
     * Empty for a project whose workspace is gone: nobody is left to tell.
     *
     * @return Collection<int, User>
     */
    public function projectRecipients(Project $project, ?User $preferred = null): Collection
    {
        $managers = $project->workspace?->projectManagers();

        if ($managers === null) {
            return new Collection;
        }

        if ($preferred !== null && $managers->contains('id', $preferred->id)) {
            return new Collection([$managers->firstWhere('id', $preferred->id)]);
        }

        return new Collection($managers->all());
    }
}
