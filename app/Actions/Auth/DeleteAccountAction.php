<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Profile\RemoveAvatarAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\PurgeDeletedProjectsAction;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Exceptions\Auth\AccountDeletionConflictException;
use App\Exceptions\Auth\AdminAccountDeletionException;
use App\Exceptions\Workspace\LastWorkspaceOwnerException;
use App\Models\AuditEvent;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\Account\AccountDeletedNotification;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Removes the account and everything personal at once. The personal workspace's
 * projects leave through the normal delete path, so their workloads stop and
 * their rows wait for the purge window without an owner; their secrets do not
 * wait. Then the personal workspace goes, and so do the account's memberships.
 *
 * Teams outlive the account. Projects it created on a team stay with the team
 * and lose only their creator. A team it solely owns is deleted with it when
 * empty, and blocks the deletion with LAST_WORKSPACE_OWNER while it still has
 * projects: the console asks to hand the team over or empty it first.
 *
 * Every step can run again, so a crash halfway leaves an account that can simply
 * repeat the request. The foreign keys from projects to users and workspaces stay
 * RESTRICT, so a project created meanwhile can make this fail, never leave an
 * orphan running.
 */
final class DeleteAccountAction
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly DeleteProjectAction $deleteProject,
        private readonly PurgeDeletedProjectsAction $purge,
        private readonly RemoveAvatarAction $removeAvatar,
    ) {}

    public function handle(User $user): void
    {
        if ($user->isAdmin()) {
            throw new AdminAccountDeletionException;
        }

        $email = $user->email;
        $emails = array_values(array_filter([$email, $user->pending_email]));

        // Before anything is deleted, so a refusal leaves the account untouched.
        $this->assertNoTeamLeftWithoutOwner($user, lock: false);

        $personalId = $user->personalWorkspace()->value('id');

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // Outside the final transaction: each delete commits on its own, and
            // cancelling attempts afterwards needs that commit to have happened.
            Project::query()
                ->where('workspace_id', $personalId)
                ->each(fn (Project $project) => $this->deleteProject->handle($project, $user));

            $this->removeAvatar->handle($user);

            if ($this->removeUser($user, $personalId, $emails)) {
                $this->sendConfirmation($email);

                return;
            }
        }

        throw new AccountDeletionConflictException;
    }

    /**
     * @param  list<string>  $emails
     */
    private function removeUser(User $user, ?string $personalId, array $emails): bool
    {
        return DB::transaction(function () use ($user, $personalId, $emails): bool {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($personalId !== null) {
                Workspace::query()->whereKey($personalId)->lockForUpdate()->first();
            }

            // Creating a project takes the same locks, so this answer stays true.
            if ($personalId !== null && Project::query()->where('workspace_id', $personalId)->exists()) {
                return false;
            }

            $emptyTeams = $this->assertNoTeamLeftWithoutOwner($user, lock: true);

            $projects = Project::onlyTrashed()->where('workspace_id', $personalId)->get();
            $projects->each(fn (Project $project) => $this->purge->eraseSecrets($project));
            Project::onlyTrashed()->where('workspace_id', $personalId)->update(['user_id' => null, 'workspace_id' => null]);

            foreach ($emptyTeams as $team) {
                Project::onlyTrashed()->where('workspace_id', $team->id)->get()
                    ->each(fn (Project $project) => $this->purge->eraseSecrets($project));
                Project::onlyTrashed()->where('workspace_id', $team->id)->update(['workspace_id' => null]);
                WorkspaceAudit::record($user, 'workspace.deleted', $team, ['reason' => 'account_deleted']);
                $team->delete();
            }

            // Projects it created on teams stay there, without a creator.
            Project::withTrashed()->where('user_id', $user->id)->update(['user_id' => null]);

            WorkspaceMember::query()->where('user_id', $user->id)->delete();

            if ($personalId !== null) {
                Workspace::query()->whereKey($personalId)->delete();
            }

            $installationIds = $user->githubInstallations()->pluck('github_installations.id')->all();

            $user->notifications()->delete();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => (string) $user->id,
                'action' => 'account.deleted',
                'subject_type' => User::class,
                'subject_id' => (string) $user->id,
                'metadata' => ['deleted_projects' => $projects->count(), 'deleted_teams' => $emptyTeams->count()],
            ]);

            // OAuth accounts, feedback, legal acceptances, and installation links
            // cascade; deployments this user requested keep running history with
            // `requested_by` set to null.
            $user->delete();

            // What only this account connected: it names a GitHub account. A team
            // project still using it keeps it.
            GithubInstallation::query()
                ->whereKey($installationIds)
                ->whereDoesntHave('users')
                ->whereDoesntHave('projects')
                ->delete();

            return true;
        }, 3);
    }

    /**
     * Teams the user owns alone. Those that still have projects refuse the
     * deletion; the empty ones are returned to be deleted with the account.
     * With $lock the team rows are locked, in ID order, inside the caller's
     * transaction, so a project created meanwhile is either seen or blocked.
     *
     * @return Collection<int, Workspace>
     */
    private function assertNoTeamLeftWithoutOwner(User $user, bool $lock): Collection
    {
        $teams = Workspace::query()
            ->where('type', WorkspaceType::Team)
            ->whereHas('members', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('role', WorkspaceRole::Owner))
            ->whereDoesntHave('members', fn (Builder $query) => $query
                ->where('user_id', '!=', $user->id)
                ->where('role', WorkspaceRole::Owner))
            ->orderBy('id')
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->get();

        $withProjects = $teams->filter(fn (Workspace $team): bool => $team->projects()->exists());

        if ($withProjects->isNotEmpty()) {
            throw new LastWorkspaceOwnerException($withProjects->values(), accountDeletion: true);
        }

        return $teams;
    }

    private function sendConfirmation(string $email): void
    {
        if (! config('sakala.email_delivery_enabled')) {
            return;
        }

        try {
            Notification::route('mail', $email)->notify(new AccountDeletedNotification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
