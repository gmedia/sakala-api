<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Enums\RepositoryAccessState;
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
 * Whether a project's owner can still read its repository on GitHub right now.
 * The installation pivot only records that they could when they connected it, so
 * a collaborator removed later would otherwise keep deploying new private commits.
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

    public function __construct(
        private readonly GithubAppOAuthService $oauth,
    ) {}

    public function forOwner(Project $project): RepositoryAccessState
    {
        $key = "github-repository-access:{$project->user_id}:{$project->github_repository_id}";

        /** @var array{state: string, checked_at: int}|null $cached */
        $cached = Cache::get($key);
        $age = $cached === null ? null : now()->getTimestamp() - $cached['checked_at'];
        $state = $cached === null ? null : RepositoryAccessState::from($cached['state']);

        if ($age !== null && $age < ($state === RepositoryAccessState::Granted ? self::GRANTED_TTL_SECONDS : self::REVOKED_TTL_SECONDS)) {
            return $state;
        }

        $answer = $this->ask($project);

        if ($answer === RepositoryAccessState::Unknown) {
            return $state ?? RepositoryAccessState::Unknown;
        }

        Cache::put($key, ['state' => $answer->value, 'checked_at' => now()->getTimestamp()], self::OUTAGE_GRACE_SECONDS);

        return $answer;
    }

    private function ask(Project $project): RepositoryAccessState
    {
        $account = OAuthAccount::query()
            ->where('user_id', $project->user_id)
            ->where('provider', 'github')
            ->first();

        // Without a GitHub link there is no way to show the owner still has access.
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
