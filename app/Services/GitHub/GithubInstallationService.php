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
use Illuminate\Http\Client\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

final class GithubInstallationService
{
    public function __construct(
        private readonly GithubAppOAuthService $oauth,
        private readonly GithubInstallationTokenService $tokens,
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

        return GithubInstallation::query()->updateOrCreate(
            ['github_installation_id' => $githubInstallationId],
            [
                'user_id' => $user->id,
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
    }

    /** @return LengthAwarePaginator<int, GithubRepositoryData> */
    public function repositories(GithubInstallation $installation, int $page, int $perPage): LengthAwarePaginator
    {
        $response = $this->installationRequest($installation)->get(
            "https://api.github.com/installation/repositories?page={$page}&per_page={$perPage}",
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
    public function branches(GithubInstallation $installation, int $repositoryId): array
    {
        $repositoryResponse = $this->installationRequest($installation)
            ->get("https://api.github.com/repositories/{$repositoryId}");
        $this->throwIfRepositoryAccessRemoved($repositoryResponse);
        $repository = $repositoryResponse->throw()->json();
        if (! is_array($repository) || ! is_string($repository['full_name'] ?? null)) {
            throw new \RuntimeException('GitHub repository response is invalid.');
        }

        $response = $this->installationRequest($installation)
            ->get("https://api.github.com/repos/{$repository['full_name']}/branches?per_page=100");
        $this->throwIfRepositoryAccessRemoved($response);
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
    public function repository(GithubInstallation $installation, int $repositoryId): array
    {
        $response = $this->installationRequest($installation)
            ->get("https://api.github.com/repositories/{$repositoryId}");
        $this->throwIfRepositoryAccessRemoved($response);
        $repository = $response->throw()->json();
        if (! is_array($repository)) {
            throw new \RuntimeException('GitHub repository response is invalid.');
        }

        return $repository;
    }

    private function installationRequest(GithubInstallation $installation): PendingRequest
    {
        if ($installation->status !== GithubInstallationStatus::Active) {
            throw new HttpResponseException(response()->json([
                'message' => 'GitHub installation is no longer active. Reconnect GitHub and try again.',
            ], 409));
        }

        return Http::withToken($this->tokens->for($installation))->acceptJson();
    }

    private function throwIfRepositoryAccessRemoved(Response $response): void
    {
        if ($response->status() !== 404) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'GitHub App no longer has access to this repository. Reconnect GitHub or choose another repository.',
        ], 409));
    }

    private function account(User $user): OAuthAccount
    {
        return OAuthAccount::query()->where('user_id', $user->id)->where('provider', 'github')->firstOrFail();
    }
}
