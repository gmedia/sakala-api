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
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GithubInstallationService
{
    private const SEARCH_PAGE_LIMIT = 10;

    public function __construct(
        private readonly GithubAppOAuthService $oauth,
        private readonly GithubRepositoryMapper $repositoryMapper,
    ) {}

    public function setup(User $user, int $githubInstallationId): GithubInstallation
    {
        $account = $this->account($user);
        $accessToken = $this->oauth->accessToken($account);
        $installation = $this->installationForUser($accessToken, $githubInstallationId);

        if (! is_array($installation) || ! is_array($installation['account'] ?? null)) {
            throw new AuthorizationException('Instalasi GitHub ini tidak tersedia untuk akunmu.');
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
    public function repositories(User $user, GithubInstallation $installation, int $page, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        $this->ensureActive($installation);

        if ($search !== null && $search !== '') {
            return $this->searchRepositories($user, $installation, $page, $perPage, $search);
        }

        $payload = $this->repositoryPage($user, $installation, $page, $perPage);

        return new LengthAwarePaginator(
            $this->mapRepositories($payload),
            (int) ($payload['total_count'] ?? 0),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * GitHub cannot filter this endpoint, so the search walks the pages here.
     * It stops at SEARCH_PAGE_LIMIT pages to stay bounded; an installation with
     * more repositories than that is searched only within the first ones.
     *
     * @return LengthAwarePaginator<int, GithubRepositoryData>
     */
    private function searchRepositories(User $user, GithubInstallation $installation, int $page, int $perPage, string $search): LengthAwarePaginator
    {
        $matches = [];
        $githubPage = 1;

        do {
            $payload = $this->repositoryPage($user, $installation, $githubPage, 100);
            $repositories = $this->mapRepositories($payload);

            foreach ($repositories as $repository) {
                if (Str::contains($repository->fullName, $search, ignoreCase: true)) {
                    $matches[] = $repository;
                }
            }

            $githubPage++;
        } while (count($repositories) === 100 && $githubPage <= self::SEARCH_PAGE_LIMIT);

        return new LengthAwarePaginator(
            array_slice($matches, ($page - 1) * $perPage, $perPage),
            count($matches),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /** @return array<array-key, mixed> */
    private function repositoryPage(User $user, GithubInstallation $installation, int $page, int $perPage): array
    {
        $payload = $this->userRequest($user)->get(
            "https://api.github.com/user/installations/{$installation->github_installation_id}/repositories?page={$page}&per_page={$perPage}",
        )->throw()->json();

        if (! is_array($payload)) {
            throw new \RuntimeException('GitHub installation repositories response is invalid.');
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<GithubRepositoryData>
     */
    private function mapRepositories(array $payload): array
    {
        return array_values(
            collect(is_array($payload['repositories'] ?? null) ? $payload['repositories'] : [])
                ->filter(fn (mixed $repository): bool => is_array($repository))
                ->map(fn (array $repository) => $this->repositoryMapper->toData($repository))
                ->all(),
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

        throw new AuthorizationException('Repository GitHub ini tidak tersedia untuk akunmu.');
    }

    private function ensureActive(GithubInstallation $installation): void
    {
        if ($installation->status === GithubInstallationStatus::Active) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Instalasi GitHub ini sudah tidak aktif. Hubungkan ulang GitHub, lalu coba lagi.',
        ], 409));
    }

    /** @return array<string, mixed>|null */
    private function installationForUser(string $accessToken, int $githubInstallationId): ?array
    {
        $page = 1;
        do {
            $payload = Http::withToken($accessToken)->acceptJson()->get(
                "https://api.github.com/user/installations?page={$page}&per_page=100",
            )->throw()->json();
            if (! is_array($payload)) {
                throw new \RuntimeException('GitHub installations response is invalid.');
            }
            $installations = is_array($payload['installations'] ?? null) ? $payload['installations'] : [];
            foreach ($installations as $installation) {
                if (is_array($installation) && ($installation['id'] ?? null) === $githubInstallationId) {
                    return $installation;
                }
            }
            $page++;
        } while ($installations !== [] && $page <= (int) ceil(((int) ($payload['total_count'] ?? 0)) / 100));

        return null;
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
