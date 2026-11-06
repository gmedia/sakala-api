<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Profile\RemoveAvatarAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\PurgeDeletedProjectsAction;
use App\Exceptions\Auth\AccountDeletionConflictException;
use App\Exceptions\Auth\AdminAccountDeletionException;
use App\Models\AuditEvent;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Account\AccountDeletedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Removes the account and everything personal at once. Projects leave through
 * the normal delete path, so their workloads stop and their rows wait for the
 * purge window without an owner; their secrets do not wait.
 *
 * Every step can run again, so a crash halfway leaves an account that can simply
 * repeat the request. The foreign key from projects to users stays RESTRICT, so
 * a project created meanwhile can make this fail, never leave an orphan running.
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

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // Outside the final transaction: each delete commits on its own, and
            // cancelling attempts afterwards needs that commit to have happened.
            Project::query()
                ->where('user_id', $user->id)
                ->each(fn (Project $project) => $this->deleteProject->handle($project, $user));

            $this->removeAvatar->handle($user);

            if ($this->removeUser($user, $emails)) {
                $this->sendConfirmation($email);

                return;
            }
        }

        throw new AccountDeletionConflictException;
    }

    /**
     * @param  list<string>  $emails
     */
    private function removeUser(User $user, array $emails): bool
    {
        return DB::transaction(function () use ($user, $emails): bool {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Creating a project takes the same lock, so this answer stays true.
            if (Project::query()->where('user_id', $user->id)->exists()) {
                return false;
            }

            $projects = Project::onlyTrashed()->where('user_id', $user->id)->get();
            $projects->each(fn (Project $project) => $this->purge->eraseSecrets($project));
            Project::onlyTrashed()->where('user_id', $user->id)->update(['user_id' => null]);

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
                'metadata' => ['deleted_projects' => $projects->count()],
            ]);

            // OAuth accounts, feedback, legal acceptances, and installation links
            // cascade; deployments this user requested keep running history with
            // `requested_by` set to null.
            $user->delete();

            // What only this account connected: it names a GitHub account.
            GithubInstallation::query()
                ->whereKey($installationIds)
                ->whereDoesntHave('users')
                ->whereDoesntHave('projects', fn (Builder $query) => $query->whereNotNull('user_id'))
                ->delete();

            return true;
        }, 3);
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
