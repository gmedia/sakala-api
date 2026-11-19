<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Data\Notification\ProjectAlertMailData;
use App\Enums\NotificationCategory;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Project\ProjectAlertMailNotification;
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
    public function __construct(
        private readonly ProjectAlertMailCoalescer $coalescer,
    ) {}

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

    /**
     * Emails a project alert to the person behind it (who asked for the
     * deployment, or who a push deploys as) while they can still act on the
     * project, and to the workspace's owners; never to viewers. Each of them
     * gets it only with email on for its category and a verified address, and
     * at most once per coalescing window. Independent of the in-app
     * notification, which keeps its own recipients and preference.
     */
    public function emailProject(Project $project, ProjectAlertMailData $alert, ?User $requester = null): void
    {
        if (! config('sakala.email_delivery_enabled')) {
            return;
        }

        $category = $alert->alert->category();
        $recipients = $this->emailRecipients($project, $requester)
            ->filter(static fn (User $user): bool => $user->email_verified_at !== null && $user->wantsNotificationEmail($category))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($recipients, $alert): void {
            foreach ($recipients as $user) {
                try {
                    $heldBack = $this->coalescer->claim($user, $alert->projectId, $alert->alert);

                    if ($heldBack !== null) {
                        $user->notify(new ProjectAlertMailNotification($alert, $heldBack));
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    /**
     * The workspace's owners, plus the requester while they are an owner or a
     * member there. Empty for a project whose workspace is gone.
     *
     * @return Collection<int, User>
     */
    public function emailRecipients(Project $project, ?User $requester = null): Collection
    {
        $workspace = $project->workspace;

        if ($workspace === null) {
            return new Collection;
        }

        $recipients = new Collection($workspace->owners()->all());

        if ($requester !== null
            && ! $recipients->contains('id', $requester->id)
            && $workspace->roleOf($requester)?->managesProjects() === true) {
            $recipients->prepend($requester);
        }

        return $recipients;
    }
}
