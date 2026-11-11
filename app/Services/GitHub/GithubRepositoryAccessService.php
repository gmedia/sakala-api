<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Enums\RepositoryAccessState;
use App\Enums\WorkspaceType;
use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Models\OAuthAccount;
use App\Models\Project;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Whether a GitHub App project may still read its repository right now.
 *
 * Who the access belongs to depends on the workspace and the installation:
 * - A team project from an installation on an organisation account uses the
 *   installation itself. No person is checked; the organisation decides which
 *   repositories the installation sees, and minting the repository-scoped token
 *   fails once the repository is removed from it.
 * - Every other GitHub App project goes through its creator: in a personal
 *   workspace that is the owner, as before workspaces. On a team the creator
 *   must also still be an owner or member, so a private repository from a
 *   personal GitHub account stops deploying once that person leaves.
 * Public URL projects need no credential and never come here, so a creator who
 * left never breaks them.
 *
 * The installation pivot only records that the creator could read the repository
 * when they connected it, so a collaborator removed later would otherwise keep
 * deploying new private commits.
 *
 * Asks with the owner's own token, which costs from their 5000 requests per hour:
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
     * True when the access belongs to the installation rather than a person.
     */
    public function throughInstallation(Project $project): bool
    {
        return $project->workspace?->type === WorkspaceType::Team
            && $project->githubInstallation?->account_type === self::ORGANIZATION_ACCOUNT;
    }

    /**
     * The person whose access the project relies on, or null when nobody
     * qualifies any more (the creator left the team or deleted the account).
     * Not meaningful when throughInstallation() is true.
     */
    public function accessHolderId(Project $project): ?int
    {
        if ($project->user_id === null || $project->workspace === null) {
            return null;
        }

        if ($project->workspace->type === WorkspaceType::Personal) {
            return $project->user_id;
        }

        return $project->workspace->roleOf($project->user)?->managesProjects() === true ? $project->user_id : null;
    }

    public function forProject(Project $project): RepositoryAccessState
    {
        if ($this->throughInstallation($project)) {
            return RepositoryAccessState::Granted;
        }

        $holderId = $this->accessHolderId($project);

        if ($holderId === null) {
            return RepositoryAccessState::Revoked;
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
