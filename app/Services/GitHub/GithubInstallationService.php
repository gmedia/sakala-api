<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\GithubRepositoryData;
use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

final class GithubInstallationService
{
    public function __construct(
        private readonly GithubAppOAuthService $oauth,
        private readonly GithubRepositoryMapper $repositoryMapper,
    ) {}

    public function setup(User $user, int $githubInstallationId): GithubInstallation
    {
        $account = $this->account($user);
        $accessToken = $this->oauth->accessToken($account);
        $installations = Http::withToken($accessToken)->acceptJson()->get('https://api.github.com/user/installations')->throw()->json('installations', []);
        if (! is_array($installations)) {
            throw new \RuntimeException('GitHub installations response is invalid.');
        }
        $installation = collect($installations)->first(fn (mixed $item): bool => is_array($item) && ($item['id'] ?? null) === $githubInstallationId);

        if (! is_array($installation) || ! is_array($installation['account'] ?? null)) {
            throw new AuthorizationException('GitHub installation is not available to this user.');
        }

        $githubInstallation = GithubInstallation::query()->updateOrCreate(
            ['github_installation_id' => $githubInstallationId],
            [
                'account_id' => (int) $installation['account']['id'],
                'account_login' => (string) $installation['account']['login'],
                'account_type' => (string) $installation['account']['type'],
                'repository_selection' => (string) ($installation['repository_selection'] ?? 'selected'),
                'permissions' => is_array($installation['permissions'] ?? null) ? $installation['permissions'] : [],
                'status' => GithubInstallationStatus::Active,
                'suspended_at' => null,
                'removed_at' => null,
            ],
        );

        $githubInstallation->users()->syncWithoutDetaching([
            $user->id => ['last_verified_at' => now()],
        ]);

        return $githubInstallation;
    }

    /** @return LengthAwarePaginator<int, GithubRepositoryData> */
    public function repositories(User $user, GithubInstallation $installation, int $page, int $perPage): LengthAwarePaginator
    {
        $this->ensureActive($installation);
        $response = $this->userRequest($user)->get(
            "https://api.github.com/user/installations/{$installation->github_installation_id}/repositories?page={$page}&per_page={$perPage}",
        )->throw();
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('GitHub installation repositories response is invalid.');
        }
        $repositories = collect(is_array($payload['repositories'] ?? null) ? $payload['repositories'] : [])
            ->filter(fn (mixed $repository): bool => is_array($repository))
            ->map(fn (array $repository) => $this->repositoryMapper->toData($repository))
            ->values()
            ->all();

        return new LengthAwarePaginator(
            $repositories,
            (int) ($payload['total_count'] ?? count($repositories)),
            $perPage,
            $page,
        );
    }

    /** @return list<string> */
    public function branches(User $user, GithubInstallation $installation, int $repositoryId): array
    {
        $this->ensureActive($installation);
        $repository = $this->repositoryForUser($user, $installation, $repositoryId);

        $response = $this->userRequest($user)
            ->get("https://api.github.com/repos/{$repository['full_name']}/branches?per_page=100");
        $response = $response->throw()->json();
        if (! is_array($response)) {
            throw new \RuntimeException('GitHub branches response is invalid.');
        }

        $branches = [];
        foreach ($response as $branch) {
            if (is_array($branch) && is_string($branch['name'] ?? null)) {
                $branches[] = $branch['name'];
            }
        }

        return $branches;
    }

    /** @return array<string, mixed> */
    public function repositoryForUser(User $user, GithubInstallation $installation, int $repositoryId): array
    {
        $this->ensureActive($installation);

        $page = 1;
        do {
            $response = $this->userRequest($user)->get(
                "https://api.github.com/user/installations/{$installation->github_installation_id}/repositories?page={$page}&per_page=100",
            );
            if ($response->status() === 404) {
                break;
            }
            $payload = $response->throw()->json();
            if (! is_array($payload)) {
                throw new \RuntimeException('GitHub installation repositories response is invalid.');
            }
            $repositories = is_array($payload['repositories'] ?? null) ? $payload['repositories'] : [];
            foreach ($repositories as $repository) {
                if (is_array($repository) && ($repository['id'] ?? null) === $repositoryId) {
                    return $repository;
                }
            }
            $page++;
        } while ($repositories !== [] && $page <= (int) ceil(((int) ($payload['total_count'] ?? 0)) / 100));

        throw new AuthorizationException('GitHub repository is not available to this user.');
    }

    private function ensureActive(GithubInstallation $installation): void
    {
        if ($installation->status === GithubInstallationStatus::Active) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'GitHub installation is no longer active. Reconnect GitHub and try again.',
        ], 409));
    }

    private function userRequest(User $user): PendingRequest
    {
        return Http::withToken($this->oauth->accessToken($this->account($user)))->acceptJson();
    }

    private function account(User $user): OAuthAccount
    {
        return OAuthAccount::query()->where('user_id', $user->id)->where('provider', 'github')->firstOrFail();
    }
}
