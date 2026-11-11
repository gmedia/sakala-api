<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Enums\RepositoryAccessState;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Whether a GitHub App project may still read its repository right now.
 *
 * Access always rests on a person who can still act on the project:
 * - In a personal workspace that is the owner, as before workspaces.
 * - On a team it is a current owner or member who has a linked GitHub identity
 *   and is linked to the project's installation: the creator while still a
 *   member, otherwise the longest-standing owner, then member. When nobody
 *   qualifies, access is revoked, so a private repository stops deploying once
 *   the people who connected it are gone.
 * On an organisation installation the person only anchors the access: the
 *   installation token reads the repository, and the organisation decides which
 *   repositories it sees. Everywhere else the person's own GitHub token must
 *   still read the repository.
 * Public URL projects need no credential and never come here, so people
 * leaving never breaks them.
 *
 * The installation pivot only records that a person could see the installation
 * when they connected it, so a collaborator removed later would otherwise keep
 * deploying new private commits.
 *
 * Asks with that person's own token, which costs from their 5000 requests per hour:
 * a grant is reused for 5 minutes, a refusal for 1 minute so restored access works
 * quickly. During a GitHub outage the last answer holds for up to an hour, which is
 * how long a revocation can go unnoticed while GitHub cannot be asked; after that
 * the answer is Unknown and the credential lease refuses.
 */
final class GithubRepositoryAccessService
{
    private const GRANTED_TTL_SECONDS = 300;

    private const REVOKED_TTL_SECONDS = 60;

    private const OUTAGE_GRACE_SECONDS = 3600;

    /** `account_type` GitHub reports for an installation on an organisation. */
    public const ORGANIZATION_ACCOUNT = 'Organization';

    public function __construct(
        private readonly GithubAppOAuthService $oauth,
    ) {}

    /**
     * True for a team project on an organisation installation: a linked
     * member anchors the access, and no personal token reads the repository.
     */
    public function throughInstallation(Project $project): bool
    {
        return $project->workspace?->type === WorkspaceType::Team
            && $project->githubInstallation?->account_type === self::ORGANIZATION_ACCOUNT;
    }

    /**
     * The person the project's GitHub access rests on, or null when nobody
     * qualifies any more. See the class comment for the order.
     */
    public function accessHolderId(Project $project): ?int
    {
        $workspace = $project->workspace;

        if ($workspace === null || $project->github_installation_id === null) {
            return null;
        }

        if ($workspace->type === WorkspaceType::Personal) {
            return $project->user_id;
        }

        $holder = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('role', WorkspaceRole::projectManagerValues())
            ->whereIn('user_id', DB::table('github_installation_user')
                ->select('user_id')
                ->where('github_installation_id', $project->github_installation_id))
            ->whereIn('user_id', OAuthAccount::query()
                ->select('user_id')
                ->where('provider', 'github'))
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 WHEN role = ? THEN 1 ELSE 2 END', [
                $project->user_id ?? 0,
                WorkspaceRole::Owner->value,
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'user_id']);

        return $holder?->user_id;
    }

    public function forProject(Project $project): RepositoryAccessState
    {
        $holderId = $this->accessHolderId($project);

        if ($holderId === null) {
            return RepositoryAccessState::Revoked;
        }

        if ($this->throughInstallation($project)) {
            return RepositoryAccessState::Granted;
        }

        $key = "github-repository-access:{$holderId}:{$project->github_repository_id}";

        /** @var array{state: string, checked_at: int}|null $cached */
        $cached = Cache::get($key);
        $age = $cached === null ? null : now()->getTimestamp() - $cached['checked_at'];
        $state = $cached === null ? null : RepositoryAccessState::from($cached['state']);

        if ($age !== null && $age < ($state === RepositoryAccessState::Granted ? self::GRANTED_TTL_SECONDS : self::REVOKED_TTL_SECONDS)) {
            return $state;
        }

        $answer = $this->ask($project, $holderId);

        if ($answer === RepositoryAccessState::Unknown) {
            return $state ?? RepositoryAccessState::Unknown;
        }

        Cache::put($key, ['state' => $answer->value, 'checked_at' => now()->getTimestamp()], self::OUTAGE_GRACE_SECONDS);

        return $answer;
    }

    private function ask(Project $project, int $holderId): RepositoryAccessState
    {
        $account = OAuthAccount::query()
            ->where('user_id', $holderId)
            ->where('provider', 'github')
            ->first();

        // Without a GitHub link there is no way to show the creator still has access.
        if ($account === null) {
            return RepositoryAccessState::Revoked;
        }

        try {
            $response = Http::withToken($this->oauth->accessToken($account))
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get("https://api.github.com/repositories/{$project->github_repository_id}");
        } catch (ConnectionException|RequestException|LockTimeoutException) {
            return RepositoryAccessState::Unknown;
        } catch (RuntimeException) {
            // The authorization is gone and cannot be refreshed: the owner has to
            // sign in with GitHub again before this repository can be used.
            return RepositoryAccessState::Revoked;
        }

        if (GithubRateLimitedException::fromResponse($response) !== null || $response->serverError()) {
            return RepositoryAccessState::Unknown;
        }

        return $response->successful() && $response->json('id') === $project->github_repository_id
            ? RepositoryAccessState::Granted
            : RepositoryAccessState::Revoked;
    }
}
